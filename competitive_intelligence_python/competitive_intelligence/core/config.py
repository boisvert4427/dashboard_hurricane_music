from dataclasses import dataclass
import os


@dataclass(frozen=True)
class Settings:
    api_base_url: str
    api_token: str
    competitor_id: int
    batch_limit: int = 25
    after_id: int = 0
    lang_id: int = 1
    shop_id: int = 1

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            api_base_url=os.environ["CI_API_BASE_URL"].rstrip("/"),
            api_token=os.environ["CI_API_TOKEN"],
            competitor_id=int(os.environ["CI_COMPETITOR_ID"]),
            batch_limit=int(os.environ.get("CI_BATCH_LIMIT", "25")),
            after_id=int(os.environ.get("CI_AFTER_ID", "0")),
            lang_id=int(os.environ.get("CI_LANG_ID", "1")),
            shop_id=int(os.environ.get("CI_SHOP_ID", "1")),
        )

