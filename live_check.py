import io
import json
import sys
import urllib.request

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

QUESTIONS = [
    "Comment initier un paiement via pvit ?",
    "comment Obtenir les frais d'une transaction pvit",
]

for question in QUESTIONS:
    body = json.dumps({"question": question}, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        "http://127.0.0.1:8000/api/mistral-raw/ask",
        data=body,
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "Accept": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=180) as response:
        payload = json.loads(response.read().decode("utf-8"))

    answer = payload.get("answer", "")
    print("=" * 60)
    print("QUESTION:", question)
    print("citation_filtered:", payload.get("citation_filtered"), "| code_filtered:", payload.get("code_filtered"), "| mots:", len(answer.split()))
    print(answer[:900])
    print()
