# Changelog

All notable changes to `laravel-docling-rag` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Major version 0 means the public API may still change. See [Versioning](README.md#versioning).

## 0.1.1 - 2026-09-11

- Widen the `laravel/ai` constraint to `^0.1` through `^0.11`. 0.1.0 required `^0.11` only, which blocked installs in apps on older `laravel/ai` releases.

## 0.1.0 - 2026-08-16

- Initial Spec 1 release: ingest documents via Docling-serve hybrid chunking, optional Gotenberg conversion, and laravel/ai embeddings into `rag_documents` / `rag_chunks`.
