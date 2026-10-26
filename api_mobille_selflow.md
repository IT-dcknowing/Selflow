# SPÉCIFICATION DE L'API MOBILE SELFLOW

Ce document contient la spécification technique et la documentation des points d'accès (endpoints) de l'API mobile de l'application **Selflow**. Il couvre l'authentification ainsi que l'ensemble de l'interface d'administration.

---

## ℹ️ Informations Générales
- **Format des données** : Toutes les requêtes et réponses doivent être au format `JSON`.
- **Authentification** : Token-based via Laravel Sanctum. Le jeton d'accès doit être fourni dans l'en-tête de chaque requête sécurisée.
- **En-têtes (Headers) communs requis** :
  ```http
  Accept: application/json
  Content-Type: application/json
  Authorization: Bearer <votre_token_ici>
  ```

---

## 🔑 SECTION 1 : PAGE DE CONNEXION & AUTHENTIFICATION

Cette section gère l'accès sécurisé à l'application mobile et la déconnexion de l'utilisateur.

### 1.1 Connexion de l'utilisateur
Permet à un utilisateur de se connecter en fournissant ses identifiants. Protégé contre les attaques par force brute (limité à 5 tentatives par minute).

- **Route** : `/api/connexion`
- **Méthode** : `POST`
- **Headers** :
  - `Accept: application/json`
  - `Content-Type: application/json`

#### Corps de la requête (Request Body)
```json
{
  "email": "admin@selflow.ci",
  "password": "mot_de_passe_secret",
  "se_souvenir": true
}
```
*Note: `se_souvenir` est un booléen optionnel qui prolonge la durée de vie du token.*

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "token": "3|aBcdEfGhIjKlMnOpQrStUvWxYz1234567890",
  "utilisateur": {
    "id": 1,
    "nom": "Koffi",
    "prenom": "Jean",
    "email": "admin@selflow.ci",
    "role": "admin",
    "fonction": "Directeur Général",
    "statut": "actif",
    "point_de_vente_id": 1,
    "entreprise_id": 1,
    "habilitations": ["ventes", "stock", "tresorerie"]
  },
  "entreprise": {
    "id": 1,
    "nom": "Selflow SARL",
    "plan_abonnement": "Premium",
    "quota_points_de_vente": 5
  }
}
```

#### Réponse d'erreur (422 Unprocessable Entity - Validation)
```json
{
  "message": "Les données fournies sont invalides.",
  "errors": {
    "email": [
      "L'adresse email est obligatoire."
    ]
  }
}
```

#### Réponse d'erreur (401 Unauthorized / Identifiants incorrects)
```json
{
  "statut": "erreur",
  "message": "Identifiants incorrects. Veuillez vérifier votre email et mot de passe."
}
```

#### Réponse d'erreur (403 Account Disabled)
```json
{
  "statut": "erreur",
  "message": "Votre compte est désactivé. Contactez votre administrateur."
}
```

---

### 1.2 Déconnexion de l'utilisateur
Invalide le jeton d'accès actuel de l'utilisateur.

- **Route** : `/api/deconnexion`
- **Méthode** : `POST`
- **Headers** : Authentification requise (`Authorization: Bearer <token>`)

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Déconnexion réussie."
}
```

---

## 📊 SECTION 2 : TABLEAU DE BORD (DASHBOARD)

Récupère l'ensemble des métriques clés de la journée pour l'administrateur, filtrées automatiquement par le point de vente actif de la session ou globalement pour l'entreprise.

- **Route** : `/api/admin/tableau-de-bord`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "entreprise": {
      "id": 1,
      "nom": "Selflow SARL"
    },
    "point_de_vente_actif_id": 1,
    "metriques_du_jour": {
      "montant_ventes_jour": 250000.00,
      "montant_achats_jour": 120000.00,
      "solde_tresorerie": 650000.00
    },
    "produits_en_alerte_stock": [
      {
        "id": 4,
        "reference": "PRD-004",
        "nom": "Huile Dinor 1L",
        "stock_actuel": 5,
        "stock_minimum": 10
      }
    ],
    "dernieres_ventes": [
      {
        "id": 12,
        "numero_facture": "VT-2026-0012",
        "date_vente": "2026-06-08",
        "client": "Client Passage",
        "montant_ttc": 45000.00,
        "statut": "Payé"
      }
    ],
    "points_de_vente": [
      {
        "id": 1,
        "nom": "Siège",
        "ventes_jour": 8,
        "montant_ventes_jour": 250000.00
      },
      {
        "id": 2,
        "nom": "Succursale Cocody",
        "ventes_jour": 3,
        "montant_ventes_jour": 95000.00
      }
    ]
  }
}
```

---

## 🛒 SECTION 3 : GESTION DES VENTES

Cette section regroupe tous les flux liés à la réalisation des ventes, à la consultation des factures, à l'historique et à la normalisation DGI.

### 3.1 Récupérer les données pour initialiser le formulaire de vente
Permet d'obtenir la liste des produits disponibles, la liste des clients et les banques configurées pour le point de vente actif.

- **Route** : `/api/admin/ventes/donnees-formulaire`
- **Méthode** : `GET`
- **Headers** : Authentification requise

> **Articles proposés (mis à jour le 26/10/2026).** La liste ne contient que
> les articles référencés sur le point de vente. Si l'entreprise a coché
> « Masquer les articles en rupture à la vente » dans ses paramètres, les
> articles stockables à zéro sur le site sont retirés, sauf pour
> l'administrateur de l'entreprise. Les prestations restent toujours.

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "clients": [
      { "id": 1, "nom": "Koffi Yao", "telephone": "0707070707" }
    ],
    "produits": [
      { 
        "id": 1, 
        "reference": "PRD-001", 
        "nom": "Riz Maman 5kg", 
        "prix_vente": 4500.00, 
        "stock_actuel": 50, 
        "unite": "Sac", 
        "categorie": "Alimentation" 
      }
    ],
    "categories": ["Alimentation", "Boissons", "Divers"],
    "banques": [
      { "id": 1, "nom": "SIB", "numero_compte": "CI001..." }
    ]
  }
}
```

---

### 3.2 Enregistrer une nouvelle vente
Enregistre une transaction de vente, met à jour le stock en temps réel (décrémentation), crée les mouvements de stock et comptabilise l'encaissement en trésorerie si la vente est réglée.

- **Route** : `/api/admin/ventes/enregistrer`
- **Méthode** : `POST`
- **Headers** : Authentification requise

> **Anti-doublon (26/10/2026).** Joignez à chaque envoi un jeton unique,
> soit dans l'en-tête `Idempotency-Key`, soit dans le champ `jeton_envoi`
> (un UUID tiré au moment où l'utilisateur valide). Le même jeton renvoyé une
> seconde fois (double appui, nouvel essai après coupure réseau) reçoit
> **409** `{"statut": "deja_enregistre"}` et n'enregistre rien. Un envoi sans
> jeton est accepté ; son numéro reste unique, mais il ne protège pas du
> double envoi.

#### Corps de la requête (Request Body)
```json
{
  "client_id": 1,
  "mode_paiement": "Espèces",
  "remise": 500.00,
  "tva_active": true,
  "montant_paye": 9000.00,
  "articles": [
    {
      "produit_id": 1,
      "quantite": 2,
      "unite": "Sac",
      "prix_unitaire": 4500.00
    },
    {
      "produit_id": null,
      "libelle_virtuel": "Emballage Carton",
      "quantite": 1,
      "unite": "Unité",
      "prix_unitaire": 200.00
    }
  ]
}
```
*Note: `mode_paiement` accepte : 'Espèces', 'Mobile Money', 'Banque', 'Crédit'. Si "Banque" est choisi, le paramètre additionnel `"banque_id"` est obligatoire.*

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Vente enregistrée avec succès !",
  "vente_id": 12,
  "numero_facture": "VT-2026-0012",
  "details": {
    "montant_ht": 9200.00,
    "remise": 500.00,
    "montant_ht_net": 8700.00,
    "montant_tva": 1566.00,
    "montant_ttc": 10266.00,
    "statut_paiement": "Avance"
  }
}
```

---

### 3.3 Liste des factures de vente (Paginée)
Retourne la liste des factures émises pour l'entreprise.

- **Route** : `/api/admin/ventes/factures`
- **Méthode** : `GET`
- **Query Params** : `page` (défaut: 1)
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "ventes": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "numero_facture": "VT-2026-0012",
        "date_vente": "2026-06-08",
        "mode_paiement": "Espèces",
        "montant_ttc": 10266.00,
        "statut": "Avance",
        "normalise": false,
        "client": { "nom": "Koffi Yao" },
        "point_de_vente": { "nom": "Siège" }
      }
    ],
    "first_page_url": "...",
    "last_page": 5,
    "total": 100
  }
}
```

---

### 3.4 Historique des ventes
- **Route** : `/api/admin/ventes/historique`
- **Méthode** : `GET`
- **Query Params** : `page` (défaut: 1)
- **Headers** : Authentification requise

*(La structure de réponse est similaire à la liste des factures mais optimisée pour l'affichage chronologique).*

---

### 3.5 Détails d'une facture spécifique
Récupère les informations complètes d'une facture pour affichage ou impression mobile.

- **Route** : `/api/admin/ventes/facture/{vente}`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "vente": {
      "id": 12,
      "numero_facture": "VT-2026-0012",
      "date_vente": "2026-06-08",
      "mode_paiement": "Espèces",
      "montant_ht": 9200.00,
      "remise": 500.00,
      "montant_tva": 1566.00,
      "montant_ttc": 10266.00,
      "statut": "Avance",
      "normalise": false,
      "qr_code_data": null,
      "client": {
        "nom": "Koffi Yao",
        "telephone": "0707070707",
        "ncc": "1234567A"
      },
      "point_de_vente": {
        "nom": "Siège",
        "ville": "Abidjan",
        "commune": "Cocody",
        "entreprise": {
          "nom": "Selflow SARL",
          "telephone": "0102030405",
          "ncc": "9876543B"
        }
      },
      "details": [
        {
          "produit_id": 1,
          "nom_produit": "Riz Maman 5kg",
          "quantite": 2,
          "unite": "Sac",
          "prix_unitaire": 4500.00,
          "montant_ttc": 9000.00
        }
      ]
    }
  }
}
```

---

### 3.6 Normaliser une facture (Simulation DGI)
Génère le code DGI de normalisation et le QR Code associé à la facture de vente.

- **Route** : `/api/admin/ventes/{vente}/normaliser`
- **Méthode** : `POST`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Facture VT-2026-0012 normalisée avec succès.",
  "qr_code_data": "VT-2026-0012|20260608135024|DGI-CI|E5C9A3F10D4B"
}
```

---

### 3.7 Modifier le statut de paiement d'une facture
Permet de modifier rapidement le statut de règlement d'une facture de vente (Ex: passer de "Crédit" à "Payé").

- **Route** : `/api/admin/ventes/{vente}/modifier`
- **Méthode** : `PUT`
- **Headers** : Authentification requise

#### Corps de la requête (Request Body)
```json
{
  "statut": "Payé",
  "mode_paiement": "Espèces"
}
```
*Note: `statut` doit être parmi : `Payé`, `Crédit`, `Avance`.*

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Facture mise à jour avec succès."
}
```

---

## 📥 SECTION 4 : GESTION DES ACHATS

Cette section gère les opérations d'approvisionnement (achats), les factures d'achats et l'augmentation des stocks en temps réel.

### 4.1 Récupérer les données pour initialiser le formulaire d'achat
- **Route** : `/api/admin/achats/donnees-formulaire`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "fournisseurs": [
      { "id": 1, "nom": "Distributeur Alimentaire CI" }
    ],
    "produits": [
      { "id": 1, "nom": "Riz Maman 5kg", "prix_achat": 3800.00 }
    ]
  }
}
```

---

### 4.2 Enregistrer un nouvel achat
Crée une facture d'achat, incrémente le stock physique, génère le mouvement de stock d'entrée et crée le décaissement en trésorerie.

- **Route** : `/api/admin/achats/enregistrer`
- **Méthode** : `POST`
- **Headers** : Authentification requise

> **Anti-doublon (26/10/2026).** Joignez à chaque envoi un jeton unique,
> soit dans l'en-tête `Idempotency-Key`, soit dans le champ `jeton_envoi`
> (un UUID tiré au moment où l'utilisateur valide). Le même jeton renvoyé une
> seconde fois (double appui, nouvel essai après coupure réseau) reçoit
> **409** `{"statut": "deja_enregistre"}` et n'enregistre rien. Un envoi sans
> jeton est accepté ; son numéro reste unique, mais il ne protège pas du
> double envoi.

#### Corps de la requête (Request Body)
```json
{
  "fournisseur_id": 1,
  "date_achat": "2026-06-08",
  "mode_paiement": "Chèque",
  "articles": [
    {
      "produit_id": 1,
      "quantite": 100,
      "prix_unitaire": 3800.00
    }
  ]
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Achat enregistré et facture générée avec succès.",
  "achat_id": 5,
  "numero_facture": "AC-2026-0005"
}
```

---

### 4.3 Liste des factures d'achat (Paginée)
- **Route** : `/api/admin/achats/factures`
- **Méthode** : `GET`
- **Query Params** : `page` (défaut: 1)
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "achats": {
    "current_page": 1,
    "data": [
      {
        "id": 5,
        "numero_facture": "AC-2026-0005",
        "date_achat": "2026-06-08",
        "montant_ttc": 448400.00,
        "fournisseur": { "nom": "Distributeur Alimentaire CI" }
      }
    ]
  }
}
```

---

### 4.4 Historique des achats
- **Route** : `/api/admin/achats/historique`
- **Méthode** : `GET`

*(Renvoie l'historique chronologique de tous les achats).*

---

### 4.5 Détail d'une facture d'achat
- **Route** : `/api/admin/achats/facture/{achat}`
- **Méthode** : `GET`
- **Headers** : Authentification requise

*(Renvoie les détails complets de la facture d'achat avec la liste des articles reçus).*

---

## 📦 SECTION 5 : GESTION DES STOCKS

Cette section donne une visibilité en temps réel sur les niveaux de stocks et l'historique des mouvements de stocks.

### 5.1 État général des stocks
Retourne la liste des produits avec leurs quantités actuelles et un statut calculé de l'état du stock.

- **Route** : `/api/admin/stock`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "stock": [
    {
      "produit_id": 1,
      "nom": "Riz Maman 5kg",
      "categorie": "Alimentation",
      "stock_actuel": 150,
      "stock_minimum": 20,
      "etat": "Normal" 
    },
    {
      "produit_id": 4,
      "nom": "Huile Dinor 1L",
      "categorie": "Alimentation",
      "stock_actuel": 5,
      "stock_minimum": 10,
      "etat": "Faible"
    }
  ]
}
```
*Note: l'état du stock peut être "Normal", "Faible" ou "Rupture".*

---

### 5.2 Historique des mouvements de stocks (Paginé)
Liste toutes les entrées (achats) et sorties (ventes) effectuées sur les stocks.

- **Route** : `/api/admin/stock/mouvements`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "mouvements": {
    "current_page": 1,
    "data": [
      {
        "id": 45,
        "produit": { "nom": "Riz Maman 5kg" },
        "point_de_vente": { "nom": "Siège" },
        "type_mouvement": "Entrée",
        "quantite": 100,
        "stock_avant": 50,
        "stock_apres": 150,
        "reference_document": "AC-2026-0005",
        "created_at": "2026-06-08 14:00:00"
      }
    ]
  }
}
```

---

## 💰 SECTION 6 : GESTION DE LA TRÉSORERIE

Cette section permet de suivre les encaissements, les décaissements et de gérer les configurations bancaires et les codes journaux.

### 6.1 Journal de Trésorerie
Retourne la liste chronologique des transactions financières (entrées/sorties) et calcule le solde actuel.

- **Route** : `/api/admin/tresorerie/journal`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "total_entrees": 350000.00,
    "total_sorties": 150000.00,
    "solde_final": 200000.00,
    "operations": [
      {
        "id": 8,
        "date_operation": "2026-06-08",
        "type_operation": "Encaissement",
        "libelle": "Vente — Facture VT-2026-0012",
        "mode_paiement": "Espèces",
        "montant_entree": 10266.00,
        "montant_sortie": 0,
        "solde_resultat": 650000.00,
        "reference_document": "VT-2026-0012"
      }
    ]
  }
}
```

---

### 6.2 Liste des Encaissements
- **Route** : `/api/admin/tresorerie/encaissements`
- **Méthode** : `GET`

*(Renvoie uniquement les opérations de type "Encaissement").*

---

### 6.3 Liste des Décaissements
- **Route** : `/api/admin/tresorerie/decaissements`
- **Méthode** : `GET`

*(Renvoie uniquement les opérations de type "Décaissement").*

---

### 6.4 Configuration des Codes Journaux
Permet d'administrer les codes de journaux comptables de l'entreprise.

- **Route** : `/api/admin/tresorerie/codes-journaux`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "codes": [
    {
      "id": 1,
      "type": "Caisse",
      "code": "CA",
      "intitule": "Caisse Principale",
      "compte": "571100"
    }
  ]
}
```

---

### 6.5 Créer un Code Journal
- **Route** : `/api/admin/tresorerie/codes-journaux`
- **Méthode** : `POST`
- **Headers** : Authentification requise

#### Corps de la requête (Request Body)
```json
{
  "type": "Caisse",
  "code": "CA",
  "intitule": "Caisse Principale",
  "compte": "571100"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Code journal créé avec succès !"
}
```

---

### 6.6 Supprimer un Code Journal
- **Route** : `/api/admin/tresorerie/codes-journaux/{code}`
- **Méthode** : `DELETE`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Code journal supprimé avec succès !"
}
```

---

### 6.7 Créer une Banque (AJAX / Mobile rapide)
Permet de configurer rapidement une nouvelle banque depuis l'interface mobile.

- **Route** : `/api/admin/banques/creer`
- **Méthode** : `POST`
- **Headers** : Authentification requise

#### Corps de la requête (Request Body)
```json
{
  "nom": "NSIA Banque",
  "numero_compte": "CI042010123456789012"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Banque configurée avec succès.",
  "banque": {
    "id": 3,
    "nom": "NSIA Banque",
    "numero_compte": "CI042010123456789012"
  }
}
```

---

## 🏢 SECTION 7 : GESTION DES POINTS DE VENTE (PDV)

Configure les succursales et gère les ouvertures/fermétures de sessions et d'aperçus en temps réel.

### 7.1 Liste des Points de Vente
- **Route** : `/api/admin/points-de-vente`
- **Méthode** : `GET`
- **Headers** : Authentification requise

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "quota_max": 5,
  "nombre_actuel": 2,
  "points_de_vente": [
    {
      "id": 1,
      "nom": "Siège",
      "ville": "Abidjan",
      "commune": "Cocody",
      "responsable": "Jean Koffi",
      "telephone": "0102030405",
      "statut": "Ouvert",
      "utilisateurs_count": 3,
      "ventes_count": 45
    }
  ]
}
```

---

### 7.2 Créer un Point de Vente (Soumis au quota d'abonnement)
- **Route** : `/api/admin/points-de-vente`
- **Méthode** : `POST`
- **Headers** : Authentification requise

#### Corps de la requête (Request Body)
```json
{
  "nom": "Succursale Zone 4",
  "ville": "Abidjan",
  "commune": "Marcory",
  "responsable": "Sarah Koné",
  "telephone": "0708091011"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Point de vente créé avec succès."
}
```

#### Réponse d'erreur (400 Bad Request - Quota dépassé)
```json
{
  "statut": "erreur",
  "message": "Quota de points de vente atteint pour votre abonnement."
}
```

---

### 7.3 Activer la Session d'un Point de Vente
Définit le Point de Vente actif pour l'utilisateur connecté dans sa session mobile.

- **Route** : `/api/admin/points-de-vente/activer/{pdv}`
- **Méthode** : `POST`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Point de vente « Succursale Zone 4 » activé pour cette session."
}
```

---

### 7.4 Activer l'Aperçu d'un Point de Vente
Permet à l'administrateur d'entrer en mode consultation (lecture seule) sur un point de vente.

- **Route** : `/api/admin/points-de-vente/activer-apercu/{pdv}`
- **Méthode** : `POST`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Aperçu du point de vente activé en mode lecture seule."
}
```

---

### 7.5 Désactiver l'Aperçu d'un Point de Vente
- **Route** : `/api/admin/points-de-vente/desactiver-apercu`
- **Méthode** : `POST`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Mode aperçu désactivé. Retour à l'administration principale."
}
```

---

## 👥 SECTION 8 : GESTION DU PERSONNEL

Administration complète des comptes employés, des rôles (admin ou caissier) et de leurs habilitations.

### 8.1 Liste des membres du personnel
- **Route** : `/api/admin/personnel`
- **Méthode** : `GET`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "personnel": [
    {
      "id": 2,
      "nom": "Konan",
      "prenom": "Bertin",
      "email": "bertin@selflow.ci",
      "role": "caissier",
      "fonction": "Caissier Principal",
      "statut": "actif",
      "point_de_vente": { "nom": "Siège" }
    }
  ]
}
```

---

### 8.2 Créer un membre du personnel
- **Route** : `/api/admin/personnel`
- **Méthode** : `POST`

#### Corps de la requête (Request Body)
```json
{
  "nom": "Konan",
  "prenom": "Bertin",
  "email": "bertin@selflow.ci",
  "password": "secret_password",
  "role": "caissier",
  "point_de_vente_id": 1,
  "fonction": "Caissier Principal",
  "date_debut_contrat": "2026-06-01",
  "date_fin_contrat": null,
  "notes": "Excellent élément",
  "habilitations": ["ventes", "stock"]
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Membre du personnel créé avec succès."
}
```

---

### 8.3 Détails d'un membre du personnel
- **Route** : `/api/admin/personnel/{personnel}`
- **Méthode** : `GET`

#### Réponse de succès (200 OK)
*(Renvoie les informations complètes du personnel avec ses habilitations).*

---

### 8.4 Modifier les informations d'un membre du personnel
- **Route** : `/api/admin/personnel/{personnel}`
- **Méthode** : `PUT`

*(Le corps de la requête prend les mêmes paramètres que la création, avec le mot de passe optionnel. Renvoie 200 OK).*

---

### 8.5 Activer ou Bloquer le statut d'un compte
- **Route** : `/api/admin/personnel/{personnel}/statut`
- **Méthode** : `POST`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Le personnel a été bloqué avec succès."
}
```
*Note: Bascule le statut de "actif" à "inactif". Un personnel inactif ne peut pas se connecter.*

---

### 8.6 Supprimer un membre du personnel
- **Route** : `/api/admin/personnel/{personnel}`
- **Méthode** : `DELETE`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Membre du personnel supprimé avec succès."
}
```

---

## 📦 SECTION 9 : GESTION DES PRODUITS (CATALOGUE)

Permet d'ajouter ou de modifier des articles dans le catalogue général de l'entreprise.

### 9.1 Liste des produits (Paginée)
- **Route** : `/api/admin/produits`
- **Méthode** : `GET`
- **Query Params** : `page` (défaut: 1)

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "produits": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "reference": "PRD-001",
        "nom": "Riz Maman 5kg",
        "categorie": "Alimentation",
        "prix_achat": 3800.00,
        "prix_vente": 4500.00,
        "stock_actuel": 150,
        "stock_minimum": 20,
        "unite": "Sac"
      }
    ]
  }
}
```

---

### 9.2 Créer un nouveau produit
- **Route** : `/api/admin/produits`
- **Méthode** : `POST`

#### Corps de la requête (Request Body)
```json
{
  "reference": "PRD-001",
  "nom": "Riz Maman 5kg",
  "categorie": "Alimentation",
  "prix_achat": 3800.00,
  "prix_vente": 4500.00,
  "stock_actuel": 150,
  "stock_minimum": 20,
  "unite": "Sac"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Produit ajouté au catalogue avec succès."
}
```

---

### 9.3 Modifier un produit
- **Route** : `/api/admin/produits/{produit}`
- **Méthode** : `PUT`

*(Le corps de la requête prend les champs à mettre à jour. Renvoie 200 OK).*

---

## 👥 SECTION 10 : GESTION DES CLIENTS

Cette section répertorie les clients enregistrés par l'entreprise.

### 10.1 Liste des clients
- **Route** : `/api/admin/clients`
- **Méthode** : `GET`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "clients": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "nom": "Koffi Yao",
        "telephone": "0707070707",
        "email": "koffi@email.com",
        "adresse": "Abidjan, Cocody",
        "ncc": "1234567A",
        "regime_imposition": "Réel Simplifié",
        "ventes_count": 14
      }
    ]
  }
}
```

---

### 10.2 Créer un client
- **Route** : `/api/admin/clients`
- **Méthode** : `POST`

#### Corps de la requête (Request Body)
```json
{
  "nom": "Koffi Yao",
  "telephone": "0707070707",
  "email": "koffi@email.com",
  "adresse": "Abidjan, Cocody",
  "ncc": "1234567A",
  "regime_imposition": "Réel Simplifié"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Client ajouté avec succès."
}
```

---

## 🚛 SECTION 11 : GESTION DES FOURNISSEURS

Gère les relations avec les fournisseurs de marchandises.

### 11.1 Liste des fournisseurs
- **Route** : `/api/admin/fournisseurs`
- **Méthode** : `GET`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "fournisseurs": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "nom": "Distributeur Alimentaire CI",
        "telephone": "0505050505",
        "email": "contact@distrib.ci",
        "adresse": "Abidjan, Marcory",
        "secteur": "Alimentation",
        "ncc": "9876543B",
        "regime_imposition": "Réel Normal",
        "achats_count": 8
      }
    ]
  }
}
```

---

### 11.2 Créer un fournisseur
- **Route** : `/api/admin/fournisseurs`
- **Méthode** : `POST`

#### Corps de la requête (Request Body)
```json
{
  "nom": "Distributeur Alimentaire CI",
  "telephone": "0505050505",
  "email": "contact@distrib.ci",
  "adresse": "Abidjan, Marcory",
  "secteur": "Alimentation",
  "ncc": "9876543B",
  "regime_imposition": "Réel Normal"
}
```

#### Réponse de succès (201 Created)
```json
{
  "statut": "succes",
  "message": "Fournisseur ajouté avec succès."
}
```

---

## ⚙️ SECTION 12 : PARAMÈTRES DE L'ENTREPRISE

Permet d'ajuster les informations légales de la société et ses coordonnées de facturation.

### 12.1 Obtenir les paramètres de l'entreprise
- **Route** : `/api/admin/entreprise/parametres`
- **Méthode** : `GET`

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "donnees": {
    "nom": "Selflow SARL",
    "adresse": "Abidjan, Plateau",
    "telephone": "0102030405",
    "email": "contact@selflow.ci",
    "rccm": "CI-ABJ-2026-B-1234",
    "compte_contribuable": "CC-1234567A",
    "ncc": "9876543B",
    "regime_imposition": "Réel Normal",
    "centre_impots": "Plateau 1",
    "ref_bancaire": "SIB: CI001...",
    "logo_path": "logos/entreprises/main.png",
    "logo_fne_path": "logos/entreprises/fne.png",
    "quota_points_de_vente": 5,
    "plan_abonnement": "Premium"
  }
}
```

---

### 12.2 Mettre à jour les paramètres de l'entreprise
Permet d'éditer les coordonnées et d'envoyer de nouveaux logos d'entreprise.

- **Route** : `/api/admin/entreprise/parametres`
- **Méthode** : `POST`
- **Headers** : 
  - `Accept: application/json`
  - `Content-Type: multipart/form-data` (Nécessaire pour l'upload d'images)

#### Paramètres de la requête (Request Body - Form-Data)
- `nom` : string (Requis, max: 150)
- `adresse` : string (Optionnel, max: 255)
- `telephone` : string (Optionnel, max: 30)
- `email` : string (Optionnel, email, max: 150)
- `rccm` : string (Optionnel, max: 100)
- `compte_contribuable` : string (Optionnel, max: 100)
- `ncc` : string (Optionnel, max: 50)
- `regime_imposition` : string (Optionnel, max: 100)
- `centre_impots` : string (Optionnel, max: 150)
- `ref_bancaire` : string (Optionnel, max: 1000)
- `logo` : file (Image: png, jpg, jpeg, svg, webp. Max: 2048 KB)
- `logo_fne` : file (Image: png, jpg, jpeg, svg, webp. Max: 2048 KB)

#### Réponse de succès (200 OK)
```json
{
  "statut": "succes",
  "message": "Paramètres de l'entreprise mis à jour avec succès."
}
```
