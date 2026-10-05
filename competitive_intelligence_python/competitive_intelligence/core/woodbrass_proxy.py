from __future__ import annotations

import json
import os
import socket
import subprocess
import time
from pathlib import Path


class WoodbrassProxyCircuit:
    def __init__(self, project_root: Path) -> None:
        self.project_root = project_root
        self.host = os.environ.get("CI_WOODBRASS_PROXY_SSH_HOST", "").strip()
        self.user = os.environ.get("CI_WOODBRASS_PROXY_SSH_USER", "root").strip() or "root"
        self.identity_file = os.environ.get("CI_WOODBRASS_PROXY_SSH_KEY", "").strip()
        self.local_port = int(os.environ.get("CI_WOODBRASS_PROXY_LOCAL_PORT", "1080"))
        self.threshold = max(1, int(os.environ.get("CI_WOODBRASS_PROXY_AFTER_429", "3")))
        self.retry_after = max(
            60, int(os.environ.get("CI_WOODBRASS_PROXY_RETRY_AFTER_SECONDS", "604800"))
        )
        self.state_path = project_root / "var" / "state" / "woodbrass-price-proxy.json"
        self.state = self._load_state()
        if self.state.get("proxy_enabled") and "next_direct_probe_at" not in self.state:
            self.state["next_direct_probe_at"] = time.time() + self.retry_after
            self._save_state()

    @property
    def configured(self) -> bool:
        return bool(self.host and self.identity_file)

    @property
    def enabled(self) -> bool:
        return self.configured and bool(self.state.get("proxy_enabled", False))

    @property
    def proxy_url(self) -> str:
        return f"socks5h://127.0.0.1:{self.local_port}"

    @property
    def should_use_proxy(self) -> bool:
        if not self.enabled:
            return False

        return time.time() < float(self.state.get("next_direct_probe_at", 0))

    def record_http_status(self, status_code: int | None, *, used_proxy: bool) -> None:
        if used_proxy:
            self.state["last_proxy_http_status"] = status_code
        elif self.enabled:
            self.state["last_direct_probe_http_status"] = status_code
            if status_code is not None and 200 <= status_code < 400:
                self.state["proxy_enabled"] = False
                self.state["consecutive_direct_429"] = 0
                self.state.pop("next_direct_probe_at", None)
            else:
                self.state["next_direct_probe_at"] = time.time() + self.retry_after
        else:
            consecutive = int(self.state.get("consecutive_direct_429", 0))
            consecutive = consecutive + 1 if status_code == 429 else 0
            self.state["consecutive_direct_429"] = consecutive
            if consecutive >= self.threshold:
                self.state["proxy_enabled"] = True
                self.state["next_direct_probe_at"] = time.time() + self.retry_after

        self.state["updated_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        self._save_state()

    def ensure_tunnel(self) -> None:
        if not self.enabled:
            return
        if self._port_is_open():
            return

        identity_path = Path(self.identity_file).expanduser()
        if not identity_path.is_file():
            raise RuntimeError(f"Woodbrass proxy SSH key not found: {identity_path}")

        command = [
            "ssh",
            "-N",
            "-D",
            f"127.0.0.1:{self.local_port}",
            "-i",
            str(identity_path),
            "-o",
            "BatchMode=yes",
            "-o",
            "ExitOnForwardFailure=yes",
            "-o",
            "ServerAliveInterval=30",
            "-o",
            "ServerAliveCountMax=3",
            "-o",
            "StrictHostKeyChecking=yes",
            "-o",
            "ConnectTimeout=10",
            f"{self.user}@{self.host}",
        ]
        process = subprocess.Popen(
            command,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
        )

        for _ in range(30):
            if self._port_is_open():
                return
            if process.poll() is not None:
                raise RuntimeError(
                    f"Unable to start Woodbrass proxy tunnel (ssh exit code {process.returncode})."
                )
            time.sleep(0.1)

        process.terminate()
        raise RuntimeError("Woodbrass proxy tunnel started but its local SOCKS port is unavailable.")

    def _port_is_open(self) -> bool:
        try:
            with socket.create_connection(("127.0.0.1", self.local_port), timeout=0.3):
                return True
        except OSError:
            return False

    def _load_state(self) -> dict[str, object]:
        try:
            data = json.loads(self.state_path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                return data
        except (OSError, ValueError):
            pass

        return {
            "consecutive_direct_429": 0,
            "proxy_enabled": False,
        }

    def _save_state(self) -> None:
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = self.state_path.with_suffix(".tmp")
        temporary_path.write_text(
            json.dumps(self.state, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        temporary_path.replace(self.state_path)
