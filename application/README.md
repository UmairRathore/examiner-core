# AI Examiner Core — Backend Platform

A production-oriented backend system for an AI-powered answer evaluation platform (O/A Levels).
This repository contains the core API, orchestration layer, and infrastructure setup.
The AI inference engine is isolated in a separate private service.

## 🧠 System Architecture

This project follows a service-separated architecture:
```
Client → Laravel API (this repo) → AI Engine (private service)
```

### Responsibilities
**examiner-core (this repository)**
- Request validation
- API endpoints
- Answer submission pipeline
- Queue & job orchestration
- Response formatting (marks, feedback, missing points)

**AI Engine (private repository)**
- Model inference
- Evaluation logic
- Prompt pipelines
- Marking scheme processing

## ⚙️ Core Features

- Structured answer evaluation pipeline
- Modular controller → service → job architecture
- Queue-based processing (async-ready)
- Redis-backed caching support
- Dockerized environment for reproducibility
- Clean separation between API and AI logic

## 🧩 Example Flow

```
POST /api/evaluate-answer

→ Validate input
→ Dispatch evaluation job
→ Send request to AI engine
→ Process structured response
→ Return marks + feedback
```

## 🐳 Local Development (Docker)

```bash
docker-compose up -d
```

Application runs at:
http://localhost:8001

## 🔐 Security & Architecture Notes

- AI logic is intentionally not included in this repository
- Environment variables are excluded via .gitignore
- System designed for scalability and service isolation

## 🚀 Roadmap

**Phase 1**: Physics answer evaluator (MVP)
**Phase 2**: Multi-question evaluation support
**Phase 3**: Subject expansion (Math, CS, Chemistry)
**Phase 4**: Personalized AI tutor system

## 👨‍💻 Author

**Umair Rathore**
Backend Engineer — Laravel, APIs, AI Systems
