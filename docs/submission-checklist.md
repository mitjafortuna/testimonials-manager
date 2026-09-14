# Submission checklist

- [ ] `main` is green in CI (unit, integration, API, e2e, lint, stan)
- [ ] Live demo reachable: https://tm-dfvu.fly.dev/api/health returns `{"status":"ok","db":true}`
- [ ] Live demo login works with the documented demo credentials
- [ ] `docs/time-log.md` has a row for every phase 0–16
- [ ] README "Deliberate shortcuts" section is current (see phase 16, Task 14, Step 1)
- [ ] `README.md` Quick start works on a clean checkout (`cp .env.example .env && make build && make install && make up && make seed`)
- [ ] `make zip` produces a ZIP that runs without a local Composer install (vendor/ is shipped)
- [ ] Repository is public: https://github.com/mitjafortuna/testimonials-manager
- [ ] `LANDINGS_API_KEY` is set to the real DFVU key on the live app (owner-only step, tracked separately — see `docs/questions-for-dfvu.md`/project memory)
- [ ] Assignment contact (kadrovska@dfvu.org) has the repo link and live link
