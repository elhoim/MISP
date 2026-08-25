#!/usr/bin/env python3
"""Live coverage for the console shells.

app/Console/Command is 12,572 statements at 1.22% - MISP's single largest
dark block. Shells cannot be reached over HTTP, so they are exercised here by
invoking the cake console directly. Under the coverage instrumentation
(build/coverage/covcollect.php is an auto_prepend_file for the CLI SAPI too)
these runs are attributed like any other.

Only read-only, side-effect-free invocations are used: each shell's help and
argument-validation paths, which is where the argument parsing and output
formatting live.

Usage:
    MISP_ROOT=/var/www/MISP python3 testlive_console.py -v
"""
import os
import subprocess
import sys
import unittest

MISP_ROOT = os.environ.get("MISP_ROOT", "/var/www/MISP")
CAKE = os.path.join(MISP_ROOT, "app", "Console", "cake")

# Shells that are safe to invoke with no arguments or with `help`: they print
# usage and exit rather than mutating the instance.
SHELLS = [
    "Admin", "Event", "Server", "User", "Training", "Password",
    "EventGraph", "Live", "Log", "Sighting", "Statistics",
]


def run_cake(*args: str, timeout: int = 120) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["sudo", "-u", "www-data", CAKE, *args],
        capture_output=True, text=True, timeout=timeout, cwd=MISP_ROOT,
    )


class TestConsoleShells(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if not os.path.exists(CAKE):
            raise unittest.SkipTest(f"cake console not found at {CAKE}")

    def test_console_lists_available_commands(self) -> None:
        result = run_cake()
        self.assertLess(result.returncode, 2, f"bare cake invocation failed: {result.stderr[:300]}")
        self.assertTrue(
            result.stdout or result.stderr,
            "the console must print something when invoked with no command",
        )

    def test_each_shell_reports_its_usage(self) -> None:
        """Every shell must respond to an unknown/absent subcommand with usage.

        This exercises each shell's option parser and help output - the part
        that is pure argument handling and needs no database state.
        """
        failures = []
        for shell in SHELLS:
            try:
                result = run_cake(shell)
            except subprocess.TimeoutExpired:
                failures.append((shell, "timeout"))
                continue
            output = (result.stdout or "") + (result.stderr or "")
            if not output.strip():
                failures.append((shell, "no output"))
            elif "Fatal error" in output or "PHP Fatal" in output:
                failures.append((shell, output.strip().splitlines()[0][:120]))

        self.assertEqual([], failures, f"shells failed to report usage: {failures}")

    def test_admin_shell_exposes_its_subcommands(self) -> None:
        result = run_cake("Admin")
        output = (result.stdout or "") + (result.stderr or "")
        self.assertNotIn("Fatal error", output)
        self.assertTrue(output.strip(), "Admin shell must print its subcommands")

    def test_unknown_shell_is_reported_cleanly(self) -> None:
        result = run_cake("ThisShellDoesNotExist")
        output = (result.stdout or "") + (result.stderr or "")
        self.assertNotIn("Fatal error", output, "an unknown shell must not fatal")


if __name__ == "__main__":
    unittest.main(verbosity=2)
