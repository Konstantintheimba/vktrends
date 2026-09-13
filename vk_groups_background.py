#!/usr/bin/env python3
"""Фоновый сбор VK на macOS: start, status, stop. Без автозапуска после перезагрузки."""

import argparse
from collections import Counter
from datetime import datetime
import fcntl
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import threading
import time

import find_vk_groups as finder


ROOT = Path(__file__).resolve().parent
OUTPUT = finder.DEFAULT_OUTPUT
LOG = OUTPUT / "background.log"
STATE = OUTPUT / "background.json"
LOCK = OUTPUT / ".background.lock"
SELF = Path(__file__).resolve()


def log(message):
    print(f"[{datetime.now().astimezone().isoformat(timespec='seconds')}] {message}", flush=True)


def read_state():
    try:
        return json.loads(STATE.read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        return {}


def write_state(state):
    state["updated_at"] = datetime.now().astimezone().isoformat(timespec="seconds")
    temporary = STATE.with_suffix(".tmp")
    temporary.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(STATE)


def running_pid():
    state = read_state()
    pid = state.get("pid")
    if not isinstance(pid, int) or pid <= 1:
        return None
    result = subprocess.run(["ps", "-p", str(pid), "-o", "command="],
                            capture_output=True, text=True)
    # Не посылаем сигнал постороннему процессу, которому достался старый PID.
    if result.returncode == 0 and f"{SELF} run" in result.stdout:
        return pid
    return None


def counts():
    names = {name for _, _, name in finder.read_names(finder.DEFAULT_SOURCE)}
    if not names:
        raise ValueError("В исходном файле нет названий.")
    records = finder.load_progress(OUTPUT / "progress.json")
    result = Counter(records.get(name, {}).get("status", "pending") for name in names)
    return {"total": len(names), "processed": len(names) - result["pending"],
            "found": result["found"], "pending": result["pending"]}


def start():
    pid = running_pid()
    if pid:
        print(f"Фоновый сбор уже работает, PID {pid}.")
        return 0
    # Проверяем исходник, прогресс и зависимость до отделения от терминала.
    import openpyxl  # noqa: F401
    counts()
    OUTPUT.mkdir(parents=True, exist_ok=True)
    with LOG.open("a", encoding="utf-8") as stream:
        process = subprocess.Popen([sys.executable, "-u", str(SELF), "run"],
                                   cwd=ROOT, stdin=subprocess.DEVNULL,
                                   stdout=stream, stderr=subprocess.STDOUT,
                                   start_new_session=True, close_fds=True)
    for _ in range(50):
        if running_pid() == process.pid:
            print(f"Фоновый сбор запущен, PID {process.pid}. Лог: {LOG}")
            return 0
        if process.poll() is not None:
            print(f"Процесс завершился при запуске; подробности в {LOG}.")
            return process.returncode
        time.sleep(0.1)
    print(f"Процесс создан, PID {process.pid}; проверьте status и {LOG}.")
    return 1


def run():
    OUTPUT.mkdir(parents=True, exist_ok=True)
    lock = LOCK.open("a+")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        log("Фоновый управляющий процесс уже запущен.")
        lock.close()
        return 75
    stopped = threading.Event()
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: stopped.set())
    signal.signal(signal.SIGHUP, signal.SIG_IGN)
    state = {"pid": os.getpid(), "state": "starting", "worker_pid": None,
             "started_at": datetime.now().astimezone().isoformat(timespec="seconds")}
    write_state(state)
    sleeper = None
    child = None
    pause = 600
    try:
        sleeper = subprocess.Popen(["/usr/bin/caffeinate", "-i", "-w", str(os.getpid())],
                                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL)
        log("Запуск фонового сбора. Автоматический сон отключён на время работы.")
        while not stopped.is_set():
            before = counts()
            if before["pending"] == 0:
                # В том числе обновляем отчёты, если предыдущий запуск завершился
                # после записи последнего результата, но до выгрузки Excel.
                command = [sys.executable, "-u", str(ROOT / "find_vk_groups.py"), "--export-only"]
            else:
                command = [sys.executable, "-u", str(ROOT / "find_vk_groups.py")]
            child = subprocess.Popen(command, cwd=ROOT, stdin=subprocess.DEVNULL)
            state.update(state="running", worker_pid=child.pid, retry_at=None)
            write_state(state)
            while child.poll() is None:
                if stopped.wait(1):
                    try:
                        child.send_signal(signal.SIGINT)
                    except ProcessLookupError:
                        pass
                    try:
                        child.wait(timeout=30)
                    except subprocess.TimeoutExpired:
                        child.terminate()
                        child.wait(timeout=10)
                    break
            code = child.wait()
            state.update(worker_pid=None, last_exit_code=code)
            child = None
            if stopped.is_set() or code == 130:
                break
            after = counts()
            state.update(progress=after)
            if code == 0 and after["pending"] == 0:
                state["state"] = "completed"
                log(f"Готово: обработано {after['processed']}; совпадений {after['found']}.")
                return 0
            if code not in (1, 75):
                state["state"] = "error"
                log(f"Неожиданный код выхода {code}; остановлено. Проверьте лог.")
                return 1
            if after["processed"] > before["processed"]:
                pause = 600
            # Блокировку другим сборщиком ждём без запуска второго писателя.
            wait_seconds = 60 if code == 75 else pause
            state.update(state="waiting", retry_at=time.time() + wait_seconds,
                         retry_in_seconds=wait_seconds)
            write_state(state)
            log(f"Осталось {after['pending']} названий. Повтор через {wait_seconds // 60} мин.")
            if code != 75:
                pause = min(pause * 2, 3600)
            stopped.wait(wait_seconds)
        state["state"] = "stopped"
        log("Фоновый сбор остановлен. Сохранённый прогресс можно продолжить командой start.")
        return 0
    except Exception as exc:
        state["state"] = "error"
        log(f"Ошибка фонового процесса: {type(exc).__name__}: {exc}")
        return 1
    finally:
        if child is not None and child.poll() is None:
            child.terminate()
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()
        if sleeper is not None and sleeper.poll() is None:
            sleeper.terminate()
            sleeper.wait()
        state["worker_pid"] = None
        write_state(state)
        lock.close()


def status():
    state = read_state()
    labels = {"starting": "Запускается", "running": "Работает", "waiting": "Ждёт повтора",
              "completed": "Завершён", "stopped": "Остановлен", "error": "Ошибка"}
    pid = running_pid()
    print(labels.get(state.get("state"), "Не запущен") if pid else "Фоновый процесс не работает.")
    if pid:
        print(f"PID: {pid}; рабочий процесс: {state.get('worker_pid') or 'нет'}")
        if state.get("state") == "waiting":
            print("Следующая попытка:", datetime.fromtimestamp(state["retry_at"]).astimezone().isoformat(timespec="seconds"))
    progress = counts()
    print(f"Обработано: {progress['processed']}/{progress['total']}; "
          f"совпадений: {progress['found']}; осталось: {progress['pending']}.")
    print(f"Лог: {LOG}")
    return 0


def stop():
    pid = running_pid()
    if not pid:
        print("Фоновый процесс уже остановлен.")
        return 0
    os.kill(pid, signal.SIGTERM)
    print("Отправлена команда остановки. Сборщик сохранит отчёты и завершится.")
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("start", "status", "stop", "run"))
    args = parser.parse_args()
    try:
        return {"start": start, "status": status, "stop": stop, "run": run}[args.action]()
    except (OSError, ValueError, ImportError) as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
