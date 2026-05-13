import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from competitive_intelligence.workers.price_job import run_price_job


if __name__ == "__main__":
    run_price_job(competitor_id=4)
