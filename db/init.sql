-- Separate database for the integration/contract test suite so test lifecycle
-- (drop_all/create_all/truncate) never touches the service's live RAG database.
CREATE DATABASE rag_test OWNER rag;

-- Dedicated database for the auth-service (user accounts + tokens). Services
-- never reach into each other's databases (constitution Gate I), so auth state
-- lives in its own database, not in the rag database.
CREATE DATABASE auth OWNER rag;

-- Separate database for the auth integration/contract test suite (same reason
-- as rag_test).
CREATE DATABASE auth_test OWNER rag;

-- Dedicated database for the inquiry-handler (factor weights + classification
-- log). The weights/scoring state is owned solely by that service; the
-- dashboard reaches it only over HTTP (constitution Gate I, FR-010, SC-006).
CREATE DATABASE inquiry_handler OWNER rag;

-- Separate database for the inquiry-handler test suite (same reason as
-- rag_test; local/CI tests actually use SQLite :memory: per research R9, but
-- this keeps the per-service DB pattern consistent).
CREATE DATABASE inquiry_handler_test OWNER rag;