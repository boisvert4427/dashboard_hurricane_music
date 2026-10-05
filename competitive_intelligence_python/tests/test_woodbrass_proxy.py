from __future__ import annotations

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from competitive_intelligence.core.woodbrass_proxy import WoodbrassProxyCircuit


class WoodbrassProxyCircuitTest(unittest.TestCase):
    def test_enables_proxy_after_three_consecutive_direct_429(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "CI_WOODBRASS_PROXY_SSH_HOST": "proxy.example",
                "CI_WOODBRASS_PROXY_SSH_KEY": "/tmp/test-key",
                "CI_WOODBRASS_PROXY_AFTER_429": "3",
            },
            clear=False,
        ):
            circuit = WoodbrassProxyCircuit(Path(directory))

            circuit.record_http_status(429, used_proxy=False)
            circuit.record_http_status(429, used_proxy=False)
            self.assertFalse(circuit.enabled)

            circuit.record_http_status(429, used_proxy=False)
            self.assertTrue(circuit.enabled)

            state = json.loads(circuit.state_path.read_text(encoding="utf-8"))
            self.assertEqual(3, state["consecutive_direct_429"])
            self.assertTrue(state["proxy_enabled"])

    def test_non_429_resets_direct_counter(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "CI_WOODBRASS_PROXY_SSH_HOST": "proxy.example",
                "CI_WOODBRASS_PROXY_SSH_KEY": "/tmp/test-key",
            },
            clear=False,
        ):
            circuit = WoodbrassProxyCircuit(Path(directory))
            circuit.record_http_status(429, used_proxy=False)
            circuit.record_http_status(200, used_proxy=False)

            self.assertEqual(0, circuit.state["consecutive_direct_429"])
            self.assertFalse(circuit.enabled)

    def test_tries_direct_again_after_delay_and_disables_proxy_on_success(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "CI_WOODBRASS_PROXY_SSH_HOST": "proxy.example",
                "CI_WOODBRASS_PROXY_SSH_KEY": "/tmp/test-key",
                "CI_WOODBRASS_PROXY_AFTER_429": "1",
                "CI_WOODBRASS_PROXY_RETRY_AFTER_SECONDS": "604800",
            },
            clear=False,
        ), patch("competitive_intelligence.core.woodbrass_proxy.time.time", return_value=1000):
            circuit = WoodbrassProxyCircuit(Path(directory))
            circuit.record_http_status(429, used_proxy=False)
            self.assertTrue(circuit.should_use_proxy)

            with patch("competitive_intelligence.core.woodbrass_proxy.time.time", return_value=605801):
                circuit = WoodbrassProxyCircuit(Path(directory))
                self.assertFalse(circuit.should_use_proxy)
                circuit.record_http_status(200, used_proxy=False)
                self.assertFalse(circuit.enabled)

    def test_failed_direct_probe_keeps_proxy_for_another_week(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "CI_WOODBRASS_PROXY_SSH_HOST": "proxy.example",
                "CI_WOODBRASS_PROXY_SSH_KEY": "/tmp/test-key",
                "CI_WOODBRASS_PROXY_AFTER_429": "1",
                "CI_WOODBRASS_PROXY_RETRY_AFTER_SECONDS": "604800",
            },
            clear=False,
        ), patch("competitive_intelligence.core.woodbrass_proxy.time.time", return_value=1000):
            circuit = WoodbrassProxyCircuit(Path(directory))
            circuit.record_http_status(429, used_proxy=False)

            with patch("competitive_intelligence.core.woodbrass_proxy.time.time", return_value=605801):
                circuit = WoodbrassProxyCircuit(Path(directory))
                circuit.record_http_status(429, used_proxy=False)
                self.assertTrue(circuit.enabled)
                self.assertTrue(circuit.should_use_proxy)
                self.assertEqual(1210601, circuit.state["next_direct_probe_at"])


if __name__ == "__main__":
    unittest.main()
