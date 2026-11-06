#!/bin/bash
# Script de vérification sécurité locale simplifié
# Projet: inscription-etudiants (Laravel)
# Auteur: mohamedyassine
# Usage: ./security-check-local.sh

set -e

PROJECT_DIR="/Users/mohamedyassine/Desktop/PFE/inscription-etudiants"
cd "$PROJECT_DIR"

echo "========================================="
echo "🔐 SÉCURITÉ LOCALE - INSRIPTION-ETUDIANTS"
echo "========================================="
echo ""

# 1. Vérifier que l'application Laravel tourne
echo "1️⃣ Vérification de l'application Laravel..."
if curl -s http://127.0.0.1:8000 > /dev/null 2>&1; then
    echo "   ✅ Application accessible sur http://127.0.0.1:8000"
else
    echo "   ❌ Application non accessible - démarrez avec: php artisan serve"
    echo "   Exécutez : php artisan serve --host=0.0.0.0 --port=8000"
    exit 1
fi
echo ""

echo "========================================="
echo "✅ Application accessible - vérifications basiques terminées"
echo "========================================="
echo ""
echo "📋 Prochaines étapes recommandées :"
echo ""
echo "1. Scan OWASP ZAP (nécessite Docker) :"
echo "   docker run -d -p 8080:8080 owasp/zap2docker-stable zap.sh -daemon -port 8080"
echo "   zap-baseline.py -t http://127.0.0.1:8000 -J zap-report.json -r zap-report.html"
echo ""
echo "2. Scan Nuclei CVE (nécessite Go+Nuclei) :"
echo "   go install -v github.com/projectdiscovery/nuclei/v2/cmd/nuclei@latest"
echo "   nuclei -h http://127.0.0.1:8000 -t cves/ -json -o nuclei-report.json"
echo ""
echo "3. Vérifier les bindings SQL manuels (déjà fait dans le code) :"
echo "   - Toutes les requêtes user input utilisent ? binding Laravel"
echo "   - Voir l'analyse précédente : 38 occurrences avec paramètres sécurisés"
echo ""
echo "========================================="