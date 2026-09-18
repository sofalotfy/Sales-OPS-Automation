from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    database_url: str = "postgresql+psycopg://rag:rag@localhost:5432/rag"
    auth_service_url: str = "http://localhost:8001"
    embedding_model: str = "BAAI/bge-small-en-v1.5"
    max_file_size_mb: int = 10
    default_top_k: int = 5
    max_top_k: int = 20
    relevance_threshold: float = 0.25


settings = Settings()