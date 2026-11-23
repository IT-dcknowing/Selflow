@extends('admin::gabarits.application')
@section('titre', 'Robot FNE en direct')
@section('topbar_titre', 'Superadmin · Robot FNE en direct')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-robot"></i> Robot FNE en direct</h1>
        <p>Les relevés du portail FNE, entreprise par entreprise : ce qui tourne, ce qui attend, ce qui a échoué. Mis à jour toutes les 3 secondes.</p>
    </div>
    <div style="font-size:13px; color:var(--text-3);"><i class="fas fa-circle" id="robot-pouls" style="color:#10b981; font-size:9px;"></i> <span id="robot-heure">…</span></div>
</div>

<div id="robot-etat" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="card" style="padding:14px;"><div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Robot</div><div id="robot-actif" style="font-weight:800; margin-top:4px;">…</div></div>
    <div class="card" style="padding:14px;"><div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Navigateur</div><div id="robot-navigateur" style="font-weight:700; margin-top:4px;">…</div></div>
    <div class="card" style="padding:14px;"><div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Prochains passages</div><div id="robot-passages" style="font-weight:700; margin-top:4px;">…</div></div>
</div>

<div id="robot-comptes" style="display:grid; gap:12px; margin-bottom:16px;"></div>

<div class="card" style="padding:0; overflow:hidden;">
    <div style="padding:10px 14px; border-bottom:1px solid var(--border); font-weight:700; font-size:13px;"><i class="fas fa-terminal"></i> Journal du robot (80 dernières lignes)</div>
    <pre id="robot-journal" style="margin:0; padding:12px 14px; height:360px; overflow:auto; background:#0b1736; color:#cfe0ff; font-size:12px; line-height:1.5; white-space:pre-wrap; word-break:break-word;">Chargement…</pre>
</div>

<script>
(function () {
    const couleurs = { libre: ['#f1f5f9', '#475569'], en_cours: ['#eff6ff', '#1d4ed8'], termine: ['#ecfdf5', '#047857'], echec: ['#fef2f2', '#b91c1c'] };
    const nomStatut = { libre: 'Libre', en_cours: 'En cours', termine: 'Terminé', echec: 'Échec' };
    const echapper = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let suivreLeBas = true;
    const journal = document.getElementById('robot-journal');
    journal.addEventListener('scroll', () => { suivreLeBas = journal.scrollTop + journal.clientHeight >= journal.scrollHeight - 20; });

    async function rafraichir() {
        try {
            const r = await fetch(@json(route('superadmin.robot.donnees')), { headers: { 'Accept': 'application/json' } });
            if (!r.ok) throw new Error();
            const d = await r.json();
            document.getElementById('robot-heure').textContent = 'Mis à jour à ' + d.heure;
            document.getElementById('robot-pouls').style.color = '#10b981';
            document.getElementById('robot-actif').innerHTML = d.actif ? '<span style="color:#047857;">Allumé</span>' : '<span style="color:#b91c1c;">Éteint (PORTAIL_FNE_SCRAPER_ACTIF)</span>';
            document.getElementById('robot-navigateur').textContent = d.navigateur ? 'Occupé par ' + d.navigateur : 'Libre';
            document.getElementById('robot-passages').textContent = d.passages.join(' · ') || 'Aucun';

            document.getElementById('robot-comptes').innerHTML = d.comptes.length ? d.comptes.map(c => `
                <div class="card" style="padding:14px;">
                    <div style="font-weight:800; margin-bottom:10px;">${echapper(c.entreprise)} <span style="color:var(--text-3); font-weight:500;">· NCC ${echapper(c.login)}</span></div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
                        ${Object.values(c.releves).map(r => `
                            <div style="border:1px solid var(--border); border-radius:10px; padding:10px 12px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <strong style="font-size:13px;">${echapper(r.libelle)}</strong>
                                    <span style="font-size:11px; font-weight:800; padding:2px 8px; border-radius:20px; background:${couleurs[r.statut][0]}; color:${couleurs[r.statut][1]};">
                                        ${r.statut === 'en_cours' ? '<i class="fas fa-spinner fa-spin"></i> ' : ''}${nomStatut[r.statut]}</span>
                                </div>
                                <div style="font-size:12px; color:var(--text-2); margin-top:6px;">${echapper(r.detail)}</div>
                                <div style="font-size:12px; color:var(--text-3); margin-top:4px;">${r.dernier ? 'Dernier import : ' + echapper(r.dernier.quand) + ' · ' + echapper(r.dernier.statut) + (r.dernier.lignes != null ? ' · ' + r.dernier.lignes + ' pièce(s)' : '') : 'Aucun import encore.'}</div>
                            </div>`).join('')}
                    </div>
                </div>`).join('') : '<div class="card" style="padding:18px; color:var(--text-3);">Aucune entreprise n\'a de relevé autorisé.</div>';

            journal.innerHTML = d.journal.length ? d.journal.map(l => {
                const couleur = /ERREUR|ERROR|échoué/.test(l) ? '#fca5a5' : (/ALERTE|WARNING|pause/.test(l) ? '#fcd34d' : (/releve\(s\)|importé|lancé/.test(l) ? '#86efac' : '#cfe0ff'));
                return `<span style="color:${couleur}">${echapper(l)}</span>`;
            }).join('\n') : 'Journal vide.';
            if (suivreLeBas) journal.scrollTop = journal.scrollHeight;
        } catch (e) {
            document.getElementById('robot-pouls').style.color = '#dc2626';
            document.getElementById('robot-heure').textContent = 'Connexion perdue, nouvel essai…';
        }
    }
    rafraichir();
    setInterval(() => { if (!document.hidden) rafraichir(); }, 3000);
})();
</script>
@endsection
