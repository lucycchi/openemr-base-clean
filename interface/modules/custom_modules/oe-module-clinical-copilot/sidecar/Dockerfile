# Clinical Co-Pilot sidecar. Small on purpose: the deployed droplet has
# 3.9 GB RAM shared with OpenEMR and MariaDB (see W2_ARCHITECTURE.md).
FROM python:3.12-slim
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends tesseract-ocr tesseract-ocr-eng && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY pyproject.toml ./
COPY copilot_sidecar ./copilot_sidecar
RUN pip install --no-cache-dir -e ".[test]"
COPY tests ./tests
COPY tools ./tools
# contracts/ is bind- or COPY-mounted one level up so anchor.py finds loinc_map.json
EXPOSE 8000
CMD ["uvicorn", "copilot_sidecar.app:app", "--host", "0.0.0.0", "--port", "8000"]
