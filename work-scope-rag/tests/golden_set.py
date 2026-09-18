"""Tiny known-answer golden set for retrieval-quality spot-checks (SC-2).

Each entry in GOLDEN_QA pairs a natural-language question with the key terms
that must appear in one of the top retrieved passages for the answer to count
as a hit. The corpus is seeded as three documents with clearly separable
topics so bge-small-en-v1.5 retrieval is deterministic on this small set.
"""

GOLDEN_CORPUS = [
    {
        "title": "Company Scope",
        "source": "golden",
        "text": (
            "Robusta Technologies provides inbound sales intelligence "
            "services. Triage of inbound sales inquiries uses "
            "retrieval-augmented generation. Enrichment of leads adds "
            "industry and company data. Automated client brief generation "
            "prepares marketing and sales meetings. Our coverage spans "
            "enterprise software companies in Europe and North America."
        ),
    },
    {
        "title": "Lead Routing",
        "source": "golden",
        "text": (
            "RAG-based triage of inbound sales inquiries routes leads to "
            "the correct account executive quickly."
        ),
    },
    {
        "title": "Brief Generation",
        "source": "golden",
        "text": (
            "The automated brief generation summarizes meeting outcomes "
            "for the sales leadership team."
        ),
    },
]

GOLDEN_QA = [
    {
        "query": "what services does Robusta provide?",
        "keys": ["triage", "enrichment", "brief"],
    },
    {
        "query": "how are inbound leads routed to account executives?",
        "keys": ["account executive"],
    },
    {
        "query": "which regions does Robusta cover?",
        "keys": ["europe", "north america"],
    },
    {
        "query": "what technology powers inquiry triage?",
        "keys": ["retrieval-augmented", "rag-based"],
    },
    {
        "query": "does Robusta enrich leads with industry data?",
        "keys": ["enrichment"],
    },
    {
        "query": "what does the brief generation summarize?",
        "keys": ["meeting outcomes", "sales leadership"],
    },
    {
        "query": "who is the target customer for Robusta?",
        "keys": ["enterprise software"],
    },
    {
        "query": "how are meeting outcomes shared with the sales team?",
        "keys": ["sales leadership", "summarizes"],
    },
    {
        "query": "what area of sales does Robusta focus on?",
        "keys": ["inbound sales", "sales intelligence"],
    },
    {
        "query": "what is Robusta's coverage geography?",
        "keys": ["north america", "europe"],
    },
]

MIN_HIT_RATE = 0.8