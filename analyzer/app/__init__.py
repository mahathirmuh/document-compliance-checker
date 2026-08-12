"""Document Trilingual Analyzer.

A small FastAPI service that extracts text from controlled documents and
measures how much English, Indonesian and Chinese each one contains.

It deliberately does not decide compliance. The service reports measurements;
the PASS / PARTIAL / FAIL verdict is applied by the Laravel application
against thresholds a Document Controller can change at runtime. Keeping the
policy out of here means retuning "how much Indonesian is enough" never
requires redeploying this service (CLAUDE.md 6, 15).
"""

__version__ = "1.0.0"
