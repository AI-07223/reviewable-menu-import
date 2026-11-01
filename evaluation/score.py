"""Score recorded extraction output against synthetic fixtures; nonzero on mismatch."""
import json
from collections import Counter
from pathlib import Path


def normalized(value):
    return " ".join(value.lower().split())


def score(expected_fixture, run):
    expected = {normalized(item["name"]): item for item in expected_fixture["items"]}
    actual = [item for category in run.get("categories", []) for item in category["items"]]
    names = Counter(normalized(item["name"]) for item in actual)
    found = {normalized(item["name"]): item for item in actual}
    matched = sorted(expected.keys() & found.keys())
    errors = []
    for name in matched:
        for field in ("price", "is_veg"):
            if expected[name][field] != found[name][field]:
                errors.append({"name": name, "field": field, "expected": expected[name][field], "actual": found[name][field]})
        variants = lambda item: sorted((normalized(v["name"]), v["price"]) for v in item.get("variations", []))
        if variants(expected[name]) != variants(found[name]):
            errors.append({"name": name, "field": "variations", "expected": variants(expected[name]), "actual": variants(found[name])})
    return {
        "fixture": run["fixture"], "expected": len(expected), "extracted": len(actual),
        "matched": len(matched), "missing": sorted(expected.keys() - found.keys()),
        "extra": sorted(found.keys() - expected.keys()),
        "duplicates": sorted(name for name, count in names.items() if count > 1),
        "field_errors": errors, "seconds": run["elapsed_seconds"],
    }


def main():
    root = Path(__file__).resolve().parent
    fixtures = {f["id"]: f for f in json.loads((root / "fixtures/expected.json").read_text())}
    runs = json.loads((root / "results/live-results.json").read_text())
    run_ids = [run["fixture"] for run in runs]
    if Counter(run_ids) != Counter(fixtures.keys()):
        raise ValueError("Expected exactly one recorded run for each fixture")
    results = [score(fixtures[run["fixture"]], run) for run in runs]
    output = json.dumps(results, indent=2) + "\n"
    (root / "results/scored-results.json").write_text(output)
    print(output)
    return int(any(row[field] for row in results for field in ("missing", "extra", "duplicates", "field_errors")))


if __name__ == "__main__":
    raise SystemExit(main())
