/**
 * Le releve des factures AVOIR (notes de credit) du portail FNE.
 *
 *   node avoirs.js --reconnaissance 1864699A <motDePasse>   explore et rapporte
 *   node avoirs.js 1864699A <motDePasse>                    releve et depose
 *   node avoirs.js 1864699A                                 mot de passe pris dans le magasin
 *   node avoirs.js --tous                                   tous les logins configures
 *
 * ## Pourquoi un troisieme fichier
 *
 * `achats.js` releve les factures RECUES (listing=received). Les avoirs sont
 * des factures EMISES de sous-type "refund" (listing=issued). Ce sont nos
 * propres pieces -- etablies et certifiees par nous -- qui corrigent une
 * facture deja emise. Le meme endpoint `/ws/invoices`, parametre different.
 *
 * Separer ce releve des ventes normales est une decision comptable : un avoir
 * n'est pas une vente, meme s'il sort du meme ecran.
 *
 * ## Le contrat de depot
 *
 *     storage/app/portail-fne/avoirs/<login>_<AAAAMMJJ>.json
 *
 * Meme regles que achats.js : pas d'horodatage de generation, decoupe au
 * dernier `_` du nom.
 */

'use strict';

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const {
  nomDeBase,
  dossierDepot,
  seConnecter,
  lireMagasin,
  motDePassePour,
  lireFileDemandes,
  journaliser,
} = require('./fne.js');

const dire = (niveau, message, details) => journaliser('avoirs', niveau, message, details);

const DOSSIER_ERREURS       = path.join(__dirname, 'erreurs');
const DOSSIER_RECONNAISSANCE = path.join(__dirname, 'reconnaissance');

const LIBELLES_PAGE = [
  'Factures emises',
  'Factures envoyees',
  'Mes factures',
  'Ventes',
  'Factures clients',
  'Gestion des factures',
];

const PAGES_MAX = 200;
const PAR_PAGE  = 100;
const CHEMIN_API = '/ws/invoices';
const DEPUIS_PAR_DEFAUT = process.env.AVOIRS_DEPUIS || '2024-01-01';

/* ------------------------------ Le mouchard ------------------------------- */

function ecouterLesReponses(page) {
  const captures = [];
  page.on('response', async reponse => {
    const url  = reponse.url();
    const type = (reponse.headers()['content-type'] || '').toLowerCase();
    if (!type.includes('json')) return;
    if (/\/_next\/|\.js(\?|$)|\.css(\?|$)/.test(url)) return;
    let corps;
    try { corps = await reponse.json(); } catch { return; }
    captures.push({ url, methode: reponse.request().method(), statut: reponse.status(), corps });
  });
  return captures;
}

function capterLAutorisation(page) {
  const etat = { valeur: null };
  page.on('request', requete => {
    if (etat.valeur || !requete.url().includes('/ws/')) return;
    const entetes = requete.headers();
    const auth = entetes.authorization || entetes.Authorization;
    if (auth) etat.valeur = auth;
  });
  return etat;
}

function extraireLesEnregistrements(valeur, profondeur = 0) {
  if (profondeur > 6 || valeur === null || typeof valeur !== 'object') return null;
  if (Array.isArray(valeur)) {
    const objets = valeur.filter(e => e && typeof e === 'object' && !Array.isArray(e));
    return objets.length === valeur.length && objets.length > 0 && Object.keys(objets[0]).length >= 3
      ? valeur : null;
  }
  let meilleur = null;
  for (const enfant of Object.values(valeur)) {
    const trouve = extraireLesEnregistrements(enfant, profondeur + 1);
    if (trouve && (!meilleur || trouve.length > meilleur.length)) meilleur = trouve;
  }
  return meilleur;
}

/* ------------------------------ La navigation ----------------------------- */

function urlDesFacturesEmises() {
  const base = String(process.env.FNE_URL || '').replace(/\/login\/?$/, '');
  return `${base}/invoice-management?type=issued`;
}

async function allerAuxFacturesEmises(page) {
  const url = urlDesFacturesEmises();
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  if (page.url().includes('invoice-management')) return url;

  for (const libelle of LIBELLES_PAGE) {
    const lien = page.getByRole('link', { name: libelle, exact: false }).first();
    if ((await lien.count()) === 0) continue;
    await Promise.all([
      page.waitForURL('**/invoice-management*', { timeout: 30000 }),
      lien.click(),
    ]);
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    return libelle;
  }

  throw new Error(
    `Page des factures emises introuvable : ni a l'adresse ${url}, ni sous les libelles `
    + LIBELLES_PAGE.map(l => `"${l}"`).join(', ')
    + '. Lancer "node avoirs.js --reconnaissance <login> <mdp>" pour voir ce que le portail propose.'
  );
}

function optionDepuis() {
  const donnee = process.argv.slice(2).find(a => a.startsWith('--depuis='));
  const valeur = donnee ? donnee.slice('--depuis='.length) : DEPUIS_PAR_DEFAUT;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(valeur)) {
    throw new Error(`--depuis attend une date AAAA-MM-JJ, recu "${valeur}".`);
  }
  return valeur;
}

function aujourdHui() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function optionListing() {
  const donnee = process.argv.slice(2).find(a => a.startsWith('--listing='));
  const valeur = donnee ? donnee.slice('--listing='.length) : 'tous';
  if (!['issued', 'received', 'tous'].includes(valeur)) {
    throw new Error(`--listing attend issued, received ou tous, recu "${valeur}".`);
  }
  return valeur;
}

/**
 * Interroge l'API des factures, page apres page, et filtre les avoirs.
 *
 * Le portail ne propose pas de filtre `subtype` dans l'URL : on ramene le listing
 * (issued pour les avoirs clients/ventes, received pour les avoirs fournisseurs/achats)
 * et on ne garde que subtype=refund.
 */
async function interrogerLesAvoirs(page, autorisation, depuis, jusquA, listing = 'tous') {
  const base = String(process.env.FNE_URL || '').replace(/\/fr\/login\/?$/, '').replace(/\/$/, '');
  const avoirs   = [];
  let totalBrut  = 0;
  const cibles = listing === 'tous' ? ['issued', 'received'] : [listing];

  for (const cible of cibles) {
    let totalCible = null;
    for (let numero = 1; numero <= PAGES_MAX; numero++) {
      const url = `${base}${CHEMIN_API}?page=${numero}&perPage=${PAR_PAGE}`
        + `&fromDate=${depuis}&toDate=${jusquA}&sortBy=-date&listing=${cible}&complete=true`;

      const reponse = await page.request.get(url, {
        timeout: 60000,
        headers: autorisation.valeur ? { Authorization: autorisation.valeur } : {},
      });

      if (!reponse.ok()) {
        const cause = reponse.status() === 401 && !autorisation.valeur
          ? " -- aucun en-tete d'autorisation n'a pu etre capte sur la page" : '';
        throw new Error(`L'API des factures a repondu ${reponse.status()} sur ${url}${cause}`);
      }

      const corps = await reponse.json();

      if (!corps || !Array.isArray(corps.data)) {
        throw new Error(
          "L'API des factures ne rend plus { data: [...] } mais "
          + JSON.stringify(Object.keys(corps ?? {})) + '. Rien n\'est depose.'
        );
      }

      if (totalCible === null) totalCible = Number(corps.total ?? corps.data.length);

      const remboursements = corps.data
        .filter(f => f && f.subtype === 'refund')
        .map(f => ({
          ...f,
          listing_source: cible,
          nature_avoir: cible === 'issued' ? 'vente' : 'achat',
        }));
      avoirs.push(...remboursements);

      if (corps.data.length === 0 || numero * PAR_PAGE >= totalCible) break;
    }
    totalBrut += (totalCible ?? 0);
  }

  return { avoirs, totalBrut };
}

/* ------------------------------ Le repli DOM ------------------------------ */

async function lireLeTableau(page) {
  return page.evaluate(() => {
    const tableau = document.querySelector('table');
    if (!tableau) return null;
    const entetes = [...tableau.querySelectorAll('thead th, thead td')]
      .map(c => c.textContent.trim()).filter(Boolean);
    if (entetes.length === 0) return null;
    const lignes = [...tableau.querySelectorAll('tbody tr')]
      .map(tr => {
        const cellules = [...tr.querySelectorAll('td, th')].map(c => c.textContent.trim());
        if (cellules.every(v => v === '')) return null;
        const ligne = {};
        entetes.forEach((entete, i) => { ligne[entete] = cellules[i] ?? null; });
        return ligne;
      }).filter(Boolean);
    return { entetes, lignes };
  });
}

/* ---------------------------- La reconnaissance --------------------------- */

async function reconnaitre(navigateur, login, motDePasse) {
  const contexte = await navigateur.newContext({ acceptDownloads: true });
  const page     = await contexte.newPage();
  const captures = ecouterLesReponses(page);
  const autorisation = capterLAutorisation(page);

  try {
    dire('INFO', '   Connexion...');
    await seConnecter(page, login, motDePasse);

    captures.length = 0;
    const libelle = await allerAuxFacturesEmises(page);
    await page.waitForTimeout(1500);

    const tableau = await lireLeTableau(page);
    const boutons = await page.evaluate(() =>
      [...document.querySelectorAll('button')]
        .map(b => b.textContent.trim().replace(/\s+/g, ' '))
        .filter(t => t && t.length < 60)
    );

    const depuis = optionDepuis();
    const jusquA = aujourdHui();
    const listing = optionListing();
    let sonde = null;

    try {
      const { avoirs, totalBrut } = await interrogerLesAvoirs(page, autorisation, depuis, jusquA, listing);
      sonde = { listing, totalBrut, avoirsRamenes: avoirs.length, exemple: avoirs[0] ?? null };
      dire('INFO', `   ${listing} : ${totalBrut} facture(s), dont ${avoirs.length} avoir(s)`);
    } catch (e) {
      sonde = { listing, erreur: e.message };
      dire('ERREUR', `   ${listing} : ${e.message}`);
    }

    const rapport = {
      libelle_du_menu: libelle,
      url: page.url(),
      boutons_de_la_page: boutons,
      tableau_a_l_ecran: tableau,
      periode_sondee: { du: depuis, au: jusquA },
      sonde_issued_avoirs: sonde,
      appels_json: captures.map(c => ({
        url: c.url, methode: c.methode, statut: c.statut, corps: c.corps,
        enregistrements_detectes: extraireLesEnregistrements(c.corps)?.length ?? 0,
      })),
    };

    if (!fs.existsSync(DOSSIER_RECONNAISSANCE)) fs.mkdirSync(DOSSIER_RECONNAISSANCE, { recursive: true });
    const chemin = path.join(DOSSIER_RECONNAISSANCE, `avoirs_${nomDeBase(login)}.json`);
    fs.writeFileSync(chemin, JSON.stringify(rapport, null, 2), 'utf-8');
    dire('INFO', `   Rapport ecrit : ${chemin}`);

    return chemin;
  } finally {
    await contexte.close();
  }
}

/* -------------------------------- Le releve ------------------------------- */

async function releverDansLaSession(page, autorisation, login, dossier) {
  dire('INFO', '   Factures avoir...');
  await allerAuxFacturesEmises(page);

  const tableau = await lireLeTableau(page);
  if (!tableau) {
    throw new Error(
      "Le tableau des factures emises n'est plus sur la page : le portail a "
      + "change de structure. Rien n'est depose."
    );
  }

  const depuis  = optionDepuis();
  const jusquA  = aujourdHui();
  const listing = optionListing();
  const { avoirs, totalBrut } = await interrogerLesAvoirs(page, autorisation, depuis, jusquA, listing);

  dire('INFO', `   ${avoirs.length} avoir(s) sur ${totalBrut} pieces scrutees (${depuis} -> ${jusquA}, listing=${listing})`);

  const contenu = {
    login,
    source: `${CHEMIN_API}?subtype=refund&listing=${listing}`,
    periode: { du: depuis, au: jusquA },
    colonnes_a_l_ecran: tableau.entetes,
    factures: avoirs,
  };

  if (!fs.existsSync(dossier)) fs.mkdirSync(dossier, { recursive: true });
  const chemin = path.join(dossier, `${nomDeBase(login)}.json`);
  fs.writeFileSync(chemin, JSON.stringify(contenu, null, 2), 'utf-8');

  dire('INFO', `   ${avoirs.length} avoir(s) -> ${path.basename(chemin)}`);
  return { login, ok: true, nombre: avoirs.length, chemin };
}

/* ------------------------------ Un releve, un login ----------------------- */

async function releverUnLogin(navigateur, login, motDePasse, dossier) {
  const contexte     = await navigateur.newContext({ acceptDownloads: true });
  const page         = await contexte.newPage();
  const autorisation = capterLAutorisation(page);

  try {
    dire('INFO', '   Connexion...');
    await seConnecter(page, login, motDePasse);
    return await releverDansLaSession(page, autorisation, login, dossier);
  } catch (erreur) {
    let capture = null;
    try {
      if (!fs.existsSync(DOSSIER_ERREURS)) fs.mkdirSync(DOSSIER_ERREURS, { recursive: true });
      capture = path.join(DOSSIER_ERREURS, `avoirs_${nomDeBase(login)}.png`);
      await page.screenshot({ path: capture, fullPage: true });
    } catch { capture = null; }
    return { login, ok: false, motif: erreur.message, capture };
  } finally {
    await contexte.close();
  }
}

/* -------------------------------- Le passage ------------------------------ */

function resoudreTaches() {
  const args     = process.argv.slice(2);
  const options  = args.filter(a => a.startsWith('--'));
  const positions = args.filter(a => !a.startsWith('--'));
  const mode = options.includes('--reconnaissance') ? 'reconnaissance' : 'releve';

  if (positions.length >= 2) return { mode, logins: [positions[0]], motDePasseDirect: positions[1] };
  if (positions.length === 1) return { mode, logins: [positions[0]], motDePasseDirect: null };
  if (options.includes('--tous')) {
    const magasin = lireMagasin();
    return {
      mode,
      logins: Object.keys(magasin).filter(login => motDePassePour(login, magasin)),
      motDePasseDirect: null,
    };
  }
  return { mode, logins: lireFileDemandes(), motDePasseDirect: null };
}

async function passage() {
  if (!process.env.FNE_URL) throw new Error('FNE_URL manquant dans le .env du scraper.');

  const taches  = resoudreTaches();
  const magasin = taches.motDePasseDirect ? {} : lireMagasin();
  const dossier = path.join(dossierDepot(), 'avoirs');

  if (!taches.logins.length) { dire('INFO', 'Rien a relever.'); return; }

  dire('INFO', taches.mode === 'reconnaissance'
    ? "Mode reconnaissance : rien ne sera depose dans le dossier d'import.\n"
    : `Depot dans : ${dossier}`
  );

  const relevables = [];
  const resultats  = [];

  for (const login of taches.logins) {
    const motDePasse = taches.motDePasseDirect || motDePassePour(login, magasin);
    if (motDePasse) { relevables.push({ login, motDePasse }); continue; }
    dire('ERREUR', `Aucun mot de passe pour "${login}" dans identifiants.json.`);
    resultats.push({ login, ok: false, motif: 'mot de passe absent du magasin' });
  }

  if (relevables.length) {
    const navigateur = await chromium.launch({
      headless: process.env.FNE_HEADLESS !== 'false',
      args: ['--no-sandbox', '--single-process', '--no-zygote'],
    });
    try {
      for (const { login, motDePasse } of relevables) {
        dire('INFO', `\n-- ${login} --`);
        if (taches.mode === 'reconnaissance') {
          await reconnaitre(navigateur, login, motDePasse);
          resultats.push({ login, ok: true, nombre: 0 });
        } else {
          resultats.push(await releverUnLogin(navigateur, login, motDePasse, dossier));
        }
      }
    } finally {
      await navigateur.close();
    }
  }

  const reussis = resultats.filter(r => r.ok);
  const echoues = resultats.filter(r => !r.ok);

  dire('INFO', `\n${'-'.repeat(60)}`);
  dire('INFO', `${reussis.length} releve(s) : ${reussis.map(r => r.login).join(', ') || '(aucun)'}`);

  if (echoues.length) {
    dire('ERREUR', `${echoues.length} en echec :`);
    for (const echec of echoues) {
      dire('ERREUR', `   - ${echec.login} : ${echec.motif}`);
      if (echec.capture) dire('ERREUR', `     capture : ${echec.capture}`);
    }
    process.exitCode = 1;
  }
}

if (require.main === module) {
  passage().catch(erreur => {
    dire('ERREUR', erreur.message);
    process.exitCode = 1;
  });
}

module.exports = {
  LIBELLES_PAGE,
  extraireLesEnregistrements,
  lireLeTableau,
  allerAuxFacturesEmises,
  capterLAutorisation,
  releverDansLaSession,
};
