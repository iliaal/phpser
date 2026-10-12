"""Exercise the actual Linux workflow build commands with a fake compiler."""

import argparse
import os
from pathlib import Path
import subprocess
import tempfile


WORKFLOW = Path(__file__).resolve().parents[1] / "workflows" / "tests.yml"
JOBS = ("linux", "linux-arm", "linux-src")


def build_steps():
    # These jobs use literal run blocks. Fail closed if their layout changes.
    lines = WORKFLOW.read_text().splitlines()
    steps = {}
    job = None
    for index, line in enumerate(lines):
        if line.startswith("  ") and not line.startswith("   ") and line.endswith(":"):
            job = line.strip()[:-1]
        if job in JOBS and line == "      - name: Build extension":
            assert lines[index + 1] == "        run: |", (job, "expected literal run block")
            body = []
            for command in lines[index + 2:]:
                if command and not command.startswith("          "):
                    break
                body.append(command[10:])
            assert job not in steps, (job, "duplicate build step")
            steps[job] = "\n".join(body)
    assert set(steps) == set(JOBS), ("missing build steps", steps.keys())
    return steps


def check(repeats):
    steps = build_steps()
    with tempfile.TemporaryDirectory(prefix="phpser-build-test-") as directory:
        root = Path(directory)
        # configure/phpize succeed; make simulates compile and link outcomes.
        configure = root / "configure"
        configure.write_text("#!/bin/sh\nexit 0\n")
        configure.chmod(0o755)
        make = root / "make"
        make.write_text("""#!/bin/sh
if [ "$BUILD_WARNING" = 1 ]; then
    echo 'file.c:1: warning: regression fixture' >&2
fi
# Output well beyond a pipe buffer after an early warning. A warning reader
# that exits early must not kill a still-running producer with SIGPIPE.
i=0
while [ "$i" -lt 2000 ]; do
    printf '%s\\n' 'compiling ........................................................................................................................'
    i=$((i + 1))
done
: > producer-finished
exit "$BUILD_STATUS"
""")
        make.chmod(0o755)
        env = dict(os.environ, PATH=f"{root}:{os.environ['PATH']}")
        for _ in range(repeats):
            for job, commands in steps.items():
                for status, warning in ((0, 0), (2, 0), (0, 1), (2, 1)):
                    finished = root / "producer-finished"
                    finished.unlink(missing_ok=True)
                    result = subprocess.run(
                        ["bash", "--noprofile", "--norc", "-e", "-c",
                         # Functions remain ahead of PATH even when linux-src
                         # prepends its real PHP installation.
                         'phpize() { return 0; }\n'
                         'make() { "$BUILD_MAKE" "$@"; }\n' + commands],
                        cwd=root, env=dict(env, BUILD_MAKE=str(make),
                                           BUILD_STATUS=str(status), BUILD_WARNING=str(warning)),
                        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                    )
                    expected = status or warning
                    assert result.returncode == expected, (job, status, warning, result.returncode)
                    assert finished.exists(), (job, "producer did not finish")
                    if warning and not status:
                        assert "::error::Build produced 1 warning(s)" in result.stdout, job
    print(f"Passed {repeats * len(steps) * 4} workflow build scenarios")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repeat", type=int, default=1)
    args = parser.parse_args()
    if args.repeat < 1:
        parser.error("--repeat must be positive")
    check(args.repeat)
