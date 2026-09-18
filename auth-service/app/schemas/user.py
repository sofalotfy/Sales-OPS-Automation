import re

from pydantic import BaseModel, Field, field_validator

USERNAME_PATTERN = re.compile(r"^[A-Za-z0-9_.-]+$")


class UserCreate(BaseModel):
    username: str = Field(min_length=1, max_length=100)
    password: str = Field(min_length=12, max_length=1024)
    role: str = Field(default="user")

    @field_validator("username")
    @classmethod
    def username_must_be_well_formed(cls, value: str) -> str:
        if not USERNAME_PATTERN.fullmatch(value):
            raise ValueError(
                "username must match [A-Za-z0-9_.-] and not be blank"
            )
        return value

    @field_validator("role")
    @classmethod
    def role_must_be_supported(cls, value: str) -> str:
        if value not in ("admin", "user"):
            raise ValueError("role must be 'admin' or 'user'")
        return value


class UserUpdate(BaseModel):
    is_enabled: bool


class UserOut(BaseModel):
    user_id: str
    username: str
    role: str
    is_enabled: bool
    created_at: str
    updated_at: str


class UserListResponse(BaseModel):
    items: list[UserOut]
    total: int
    limit: int
    offset: int


class DeleteResponse(BaseModel):
    deleted: bool = Field(default=True)
    user_id: str