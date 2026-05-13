import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from competitive_intelligence.workers.url_job import run_url_job


if __name__ == "__main__":
    run_url_job(competitor_id=2, batch_mode="retry_url")
