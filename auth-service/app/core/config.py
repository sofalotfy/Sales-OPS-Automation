from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    database_url: str = "postgresql+psycopg://rag:rag@localhost:5432/auth"
    auth_initial_admin_username: str = "admin"
    auth_initial_admin_password: str | None = None
    auth_token_expiry_seconds: int = 7776000  # 90 days


settings = Settings()