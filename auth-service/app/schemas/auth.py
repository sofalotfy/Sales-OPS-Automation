from pydantic import BaseModel, Field


class LoginRequest(BaseModel):
    username: str = Field(min_length=1, max_length=100)
    password: str = Field(min_length=1, max_length=1024)


class LoginResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_at: str


class VerifyResponse(BaseModel):
    user_id: str
    username: str
    role: str


class LogoutResponse(BaseModel):
    revoked: bool = True