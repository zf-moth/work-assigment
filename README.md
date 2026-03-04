# Hromadné Platby KB

This repository contains my solution for the Creepy Studio assignment.

The task was to create a **PHP script** that generates a **BEST format** file for Komerční banka bulk refund payments. Because I thought it would be neat, I also built a web interface in **Vue.js** that communicates with the PHP back-end.

## Architecture

- **Frontend**: Vue 3 + Vite - communicates with the back-end via Fetch API through a Vite dev-server proxy.
- **Backend (API)**: Plain PHP (`api/generate.php`) - Receives JSON orders, filters refunded ones, and outputs a valid `.best` file.

### Notable merits (i.e. I was bored)

- **Business Day Logic**: Automatically skips weekends and Czech public holidays (including moveable Easter dates via `easter_days_pure` function).
- **Encoding**: Output is strictly `Windows-1250` with `CRLF` line endings, as required by the BEST format.
- **Deployment**: The API is deployed to Railway, and the frontend is deployed to GitHub Pages.

## How to Run

The project consists of two parts. The frontend proxies API calls to the PHP server on port 8000.

### Start the PHP Backend
```bash
# From the project root
php -S localhost:8000
```
The endpoint will be available at `http://localhost:8000/api/generate.php`.

### Start the Vue.js Frontend
```bash
cd frontend
npm install
npm run dev
```
The app will run on `http://localhost:5173/work-assigment/`. The Vite config proxies `/api` requests to the local PHP server (see `vite.config.js`).

## Time Spent

| Phase | Time |
|-------|------|
| Understanding the assignment & gathering BEST documentation | ~1.5 h |
| Backend (PHP) development | ~3.5 h |
| Frontend (Vue.js + CSS) development | ~1 h |
| Documentation & polish | ~1 h |
| **Total** | **~7 hours** |

### Notes
```markdown
It was pain in the ass to find the correct documentation.
> The official KB BEST format specification can be found at
> https://mojebanka.kb.cz/file/en/format_best_en.pdf
```

