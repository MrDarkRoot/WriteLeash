"""Read-only host and container observations around benchmark cells.

Missing or unreadable sensors are recorded as unavailable, never synthesized.
Snapshots run outside the timed pgbench transaction interval.
"""

import json
import os
from pathlib import Path
import subprocess


def text(path):
    try:
        return Path(path).read_text().strip()
    except OSError:
        return None


def sensors(pattern, fields):
    results = {}
    for directory in sorted(Path("/").glob(pattern)):
        results[directory.name] = {name: text(directory / name) for name in fields}
    return results or {"unavailable": "no readable sensors found"}


def host():
    meminfo = text("/proc/meminfo")
    wanted = {"MemTotal", "MemAvailable", "MemFree", "SwapTotal", "SwapFree", "Dirty", "Writeback"}
    memory = {}
    if meminfo:
        memory = {k.strip(): v.strip() for line in meminfo.splitlines()
                  if ":" in line for k, v in [line.split(":", 1)] if k in wanted}
    return {
        "load_1_5_15": os.getloadavg(),
        "memory": memory or {"unavailable": "/proc/meminfo unreadable"},
        "pressure": {kind: text(f"/proc/pressure/{kind}") for kind in ("cpu", "io", "memory")},
        "cpu_stat": next((line for line in (text("/proc/stat") or "").splitlines()
                          if line.startswith("cpu ")), None),
        "diskstats": text("/proc/diskstats"),
        "cpu_frequency": sensors("sys/devices/system/cpu/cpu[0-9]*/cpufreq",
                                 ("scaling_governor", "scaling_cur_freq", "cpuinfo_cur_freq")),
        "thermal": sensors("sys/class/thermal/thermal_zone*", ("type", "temp")),
        "power": sensors("sys/class/powercap/*", ("name", "energy_uj")),
        "battery": sensors("sys/class/power_supply/*", ("type", "status", "power_now")),
    }


def command(args):
    try:
        p = subprocess.run(args, text=True, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=15, check=False)
        if p.returncode:
            return {"unavailable": f"exit {p.returncode}: {p.stderr.strip()[:500]}"}
        return p.stdout.strip()
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {"unavailable": str(exc)}


def container(container_id):
    peers = command(["docker", "ps", "--format", "{{json .}}"])
    if isinstance(peers, str):
        try:
            entries = [json.loads(line) for line in peers.splitlines() if line]
            peers = [{"benchmark_container": item.get("ID", "").startswith(container_id[:12]),
                      "status": item.get("Status"),
                      "name": item.get("Names") if item.get("ID", "").startswith(container_id[:12])
                      else "[other-container]"} for item in entries]
        except ValueError:
            peers = {"unavailable": "unexpected docker ps output"}
    stats = command(["docker", "stats", "--no-stream", "--format", "{{json .}}", container_id])
    if isinstance(stats, str):
        try:
            stats = json.loads(stats)
        except ValueError:
            stats = {"unavailable": "unexpected docker stats output"}
    cgroups = command(["docker", "exec", container_id, "sh", "-c",
                       "for f in cpu.max cpu.stat memory.max memory.current memory.events io.stat "
                       "cpuset.cpus.effective; do if test -r /sys/fs/cgroup/$f; "
                       "then echo \"$f\"; cat /sys/fs/cgroup/$f; fi; done"])
    return {"running_containers": peers, "postgres_stats": stats, "postgres_cgroups": cgroups}
