FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 WEB_HOST=0.0.0.0
WORKDIR /app
COPY bot.py web.py run.py ./
COPY static ./static
CMD ["python", "run.py"]
