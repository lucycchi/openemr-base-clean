#!/usr/bin/env python3
"""Turns tests/load/results/<stamp>-*.json and *-stats.csv into the Markdown
tables used in clinical_copilot_week1/BASELINES.md and clinical_copilot_week2/BASELINES.md.

    python3 tests/load/summarise.py <stamp> [results-dir]
"""
import csv
import json
import statistics
import sys
from pathlib import Path


def stats_summary(path: Path) -> dict:
    """Reduce a sample-stats.sh CSV to avg/peak CPU and memory per container kind.

    Containers are bucketed into "app" (openemr) and "db" (mysql/mariadb) by
    name; host load1 is tracked separately. Returns {} if the file is missing.
    """
    per = {}
    load = []
    if not path.exists():
        return {}
    with path.open() as fh:
        for row in csv.DictReader(fh):
            try:
                cpu = float(row["cpu_pct"])
                mem = float(row["mem_used_mib"])
                load.append(float(row["load1"]))
            except (ValueError, KeyError):
                continue
            name = row["container"]
            # Week 2: the sidecar is its own bucket; before, it would have been counted as app.
            kind = "db" if ("mysql" in name or "mariadb" in name) else ("sidecar" if "sidecar" in name else "app")
            per.setdefault(kind, {"cpu": [], "mem": []})
            per[kind]["cpu"].append(cpu)
            per[kind]["mem"].append(mem)
    out = {}
    for kind, v in per.items():
        if v["cpu"]:
            out[kind] = {
                "cpu_avg": round(statistics.mean(v["cpu"]), 1),
                "cpu_peak": round(max(v["cpu"]), 1),
                "mem_avg_mib": round(statistics.mean(v["mem"])),
                "mem_peak_mib": round(max(v["mem"])),
            }
    if load:
        out["load1_peak"] = max(load)
    return out


def main() -> None:
    # Every run in the matrix shares a stamp prefix; load them all, attach the
    # matching stats CSV, and print two Markdown tables.
    stamp = sys.argv[1]
    results = Path(sys.argv[2] if len(sys.argv) > 2 else "tests/load/results")
    runs = []
    for js in sorted(results.glob(f"{stamp}-*.json")):
        run = json.loads(js.read_text())
        run["stats"] = stats_summary(results / (js.stem + "-stats.csv"))
        runs.append(run)
    if not runs:
        sys.exit(f"no results for stamp {stamp} in {results}")

    def n(v):
        return "–" if v is None else v

    print(f"Target `{runs[0]['base_url']}`, run `{stamp}`, {runs[0]['duration']} per level.\n")
    print("### Latency and errors\n")
    print("| VUs | Scenario | Requests | req/s | Briefs (cache hit %) | Asks (guideline hit %) | Model calls | Brief p50/p95/p99 (ms) | Cache-hit brief p50/p95 | Cold brief p50/p95 | Ask p50/p95/p99 (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors | Summary unavailable | Verification fail |")
    print("|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|")
    for r in runs:
        if r["scenario"] == "extract":
            continue
        L = r["latency_ms"]
        b, bh, bc, a, c = L["brief"], L["brief_cache_hit"], L["brief_cold"], L["ask"], L["chart_open"]
        print(
            f"| {r['vus']} | {r['scenario']} | {r['requests_total']} | {r['throughput_rps']} | {r['briefs']} ({n(r['brief_cache_hit_pct'])}) | {r['asks']} ({n(r.get('guideline_hit_pct'))}) | {r['model_calls']} "
            f"| {n(b['p50'])} / {n(b['p95'])} / {n(b['p99'])} | {n(bh['p50'])} / {n(bh['p95'])} | {n(bc['p50'])} / {n(bc['p95'])} "
            f"| {n(a['p50'])} / {n(a['p95'])} / {n(a['p99'])} | {n(c['p50'])} / {n(c['p95'])} "
            f"| {n(r['http_failed_pct'])} % | {n(r['copilot_request_error_pct'])} % | {n(r['summary_unavailable_pct'])} % | {n(r['verification_fail_pct'])} % |"
        )
    extract_runs = [r for r in runs if r["scenario"] == "extract"]
    if extract_runs:
        print("\n### Document extraction (Week 2)\n")
        print("| VUs | Requests | req/s | Extractions | Extracted | Fully verified | Confidence p50 | Upload p50/p95 (ms) | Extract p50/p95/p99/max (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors |")
        print("|---|---|---|---|---|---|---|---|---|---|---|---|")
        for r in extract_runs:
            L = r["latency_ms"]
            u, e, c = L.get("upload", {}), L.get("extract", {}), L["chart_open"]
            print(
                f"| {r['vus']} | {r['requests_total']} | {r['throughput_rps']} | {r.get('extractions')} | {n(r.get('extract_ok_pct'))} % | {n(r.get('extract_verified_pct'))} % | {n(r.get('extract_confidence_p50'))} "
                f"| {n(u.get('p50'))} / {n(u.get('p95'))} | {n(e.get('p50'))} / {n(e.get('p95'))} / {n(e.get('p99'))} / {n(e.get('max'))} | {n(c['p50'])} / {n(c['p95'])} "
                f"| {n(r['http_failed_pct'])} % | {n(r['copilot_request_error_pct'])} % |"
            )
    print("\n### CPU and memory on the target host\n")
    print("| VUs | Scenario | App CPU avg / peak (% of one core) | App memory avg / peak (MiB) | Sidecar CPU avg / peak | Sidecar memory avg / peak (MiB) | DB CPU avg / peak | DB memory avg / peak (MiB) | Host load1 peak |")
    print("|---|---|---|---|---|---|---|---|---|")
    for r in runs:
        s = r["stats"]
        app, db, sc = s.get("app", {}), s.get("db", {}), s.get("sidecar", {})
        print(
            f"| {r['vus']} | {r['scenario']} | {n(app.get('cpu_avg'))} / {n(app.get('cpu_peak'))} | {n(app.get('mem_avg_mib'))} / {n(app.get('mem_peak_mib'))} "
            f"| {n(sc.get('cpu_avg'))} / {n(sc.get('cpu_peak'))} | {n(sc.get('mem_avg_mib'))} / {n(sc.get('mem_peak_mib'))} "
            f"| {n(db.get('cpu_avg'))} / {n(db.get('cpu_peak'))} | {n(db.get('mem_avg_mib'))} / {n(db.get('mem_peak_mib'))} | {n(s.get('load1_peak'))} |"
        )


if __name__ == "__main__":
    main()
