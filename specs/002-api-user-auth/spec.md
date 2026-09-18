# Feature Specification: API User Authentication

**Feature Branch**: `002-api-user-auth`

**Created**: 2026-09-06

**Status**: Draft

**Input**: User description: "i want to add a user model to restrict usage for the public api with a loggin and token system to be passed in header for authorization"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - User Account Management (Priority: P1)

An administrator manages the user accounts that are allowed to use the public API. They can create a user with login credentials, see existing users, and disable a user to cut off that user's access at any time.

**Why this priority**: No request can be authenticated without accounts existing first, so this story is the foundation for the whole feature.

**Independent Test**: An actor with administrator privileges creates a new user account and the account appears in the user list; a disabled account can no longer be used to log in.

**Acceptance Scenarios**:

1. **Given** an authorized administrator, **When** they create a user with valid credentials, **Then** the account is created and is available for login.
2. **Given** an attempt to create a user with duplicate or blank credentials, **When** the create action is submitted, **Then** the system rejects it with a clear error and no account is created.
3. **Given** an existing user account, **When** an administrator disables it, **Then** the user can no longer log in and any previously issued token is treated as invalid.

---

### User Story 2 - Login and Token-Based Access to the Public API (Priority: P1)

A consumer of the public API (a person or an internal system) provides valid credentials in exchange for a token. Subsequent protected calls carry that token in an HTTP authorization header, and the API honors the request only when the token is valid. Requests with a missing, unknown, expired, or revoked token are refused without performing the requested operation.

**Why this priority**: This is the core value of the feature — it restricts who can use the public API.

**Independent Test**: A user with a valid account logs in and receives a token; a protected call with that token in the header succeeds, while the same call with no token, a wrong token, or a revoked token is refused.

**Acceptance Scenarios**:

1. **Given** a user with valid credentials, **When** they log in, **Then** the system returns a token that can be used to authorize subsequent requests for the user's session.
2. **Given** a protected API operation, **When** a valid token is supplied in the authorization header, **Then** the operation is performed normally.
3. **Given** a protected API operation, **When** no token or an invalid token is supplied, **Then** the operation is refused with a clear unauthorized response and is not performed.
4. **Given** a token that has expired or been revoked, **When** it is used, **Then** the operation is refused and the caller is informed that re-authentication is required.

---

### User Story 3 - Token Lifecycle and Account Disablement (Priority: P2)

Tokens and accounts remain governable after login. A user can log out (revoke a token), tokens expire after a defined lifetime, and disabling or deleting a user immediately invalidates that user's access without waiting for existing tokens to lapse.

**Why this priority**: Supports security and hygiene; the core access control works before this is complete, but long-lived or stray tokens cannot be controlled without it.

**Independent Test**: A logged-in user revokes a token and the next request with that token is refused; an administrator disables a user and that user's next request is refused immediately.

**Acceptance Scenarios**:

1. **Given** an active token, **When** the owning user logs out, **Then** subsequent requests with that token are refused.
2. **Given** a user account that is disabled or deleted, **When** any previously issued token for that user is presented, **Then** the request is refused immediately.
3. **Given** a token past its expiration time, **When** it is presented, **Then** the request is refused until the user logs in again to obtain a fresh token.

---

### Edge Cases

- Wrong or blank credentials supplied at login result in a clear, non-revealing error, never in a token.
- Repeated failed login attempts are throttled so credentials cannot be brute-forced.
- Two different users with identical usernames or email addresses cannot both be created.
- A token presented after the issuing user is disabled or the user record is removed is always refused.
- Concurrent or repeated logins by the same user each produce a usable token; revoking one does not affect the others unless that is the intended policy.
- The public health/probe endpoint remains reachable without a token so that readiness monitoring keeps working.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST allow only authorized administrators to create user accounts, each with unique login credentials.
- **FR-002**: System MUST verify login credentials and, on success, issue a token that authorizes the user; invalid or blank credentials MUST NOT yield a token.
- **FR-003**: System MUST require a token supplied in an HTTP authorization header for all protected operations, and MUST refuse requests with no token, an unknown token, an expired token, or a token belonging to a disabled user.
- **FR-004**: System MUST allow an administrator to disable a user account, and disabling MUST take effect immediately for that user's existing tokens.
- **FR-005**: Issued tokens MUST be valid for no longer than 90 days and MUST be refused once they have expired.
- **FR-006**: System MUST support revoking a token explicitly (for example, on logout or suspected compromise) so a revoked token is refused thereafter.
- **FR-007**: System MUST limit repeated failed login attempts from the same account or client so that credentials cannot be brute-forced.
- **FR-008**: System MUST keep a designated public health/probe operation accessible without any token.
- **FR-009**: "Restricting usage" MUST cover refusing requests that are not authorized by a valid token; per-user usage quotas and rate limiting are out of scope for this feature.

### Key Entities *(include if feature involves data)*

- **User**: A person or internal system permitted to use the public API; has unique credentials, an enabled/disabled status, and creation/update timestamps.
- **Token**: Proof of a successful login; belongs to one user, has an issue time, an expiration time, and a revoked indicator.
- **Login Attempt** (audit support for throttling): Failed login events associated with an account or client, used to enforce brute-force limits.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user with valid credentials can log in and complete a protected API call within a few seconds of account creation.
- **SC-002**: 100% of protected API operations refuse requests carrying a missing, unknown, expired, or revoked token, with no operation performed.
- **SC-003**: Disabling a user account blocks that user's very next request (no grace period via existing tokens).
- **SC-004**: Repeated wrong-password attempts are blocked within a defined number of tries so automated guessing fails.
- **SC-005**: The public health/probe endpoint remains available without authorization at all times the service is running.

## Assumptions

- "The public API" means the existing service HTTP API; the explicitly public health/probe endpoint stays open, and everything else becomes protected.
- Users are operating staff or internal systems, not end customers, so no self-service sign-up, password recovery, or open registration is in scope.
- Accounts are provisioned only by an authorized administrator; each user has unique login credentials.
- Tokens are valid for up to 90 days and are renewed by logging in again; they carry no per-user usage quota, and the feature imposes access control only (no rate limiting or usage caps).
- Tokens are passed in the standard HTTP authorization header using a widely supported token scheme.
- Account and token data will live in the shared project database so it is a single source of truth.
- Out of scope for v1: external identity provider/OAuth2 federation, multi-factor authentication, self-service password reset, and user-facing account management UI beyond the minimal administrator actions described in User Story 1.
- The existing constitutional principles continue to apply (independent deployable services, API-first design, data model as source of truth, human-in-the-loop for ambiguity, simplicity).