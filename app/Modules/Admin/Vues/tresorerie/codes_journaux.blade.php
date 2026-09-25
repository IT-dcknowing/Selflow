@extends('admin::gabarits.application')
@section('titre', 'Codes Journaux')
@section('topbar_titre', 'Trésorerie — Codes Journaux')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-book"></i> Codes Journaux</h1>
        <p>Gérez les journaux comptables et leurs comptes associés</p>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        {{-- Switch bouton --}}
        <div class="vue-toggle" style="display:flex; background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden; padding:2px;">
            <button type="button" id="btn-vue-local" onclick="switchVue('local')" class="btn btn-sm btn-primary"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
                <i class="fas fa-database"></i> Local
            </button>
            <button type="button" id="btn-vue-comptaflow" onclick="switchVue('comptaflow')" class="btn btn-sm btn-outline"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:transparent; color:var(--text-2);">
                <i class="fas fa-sync"></i> COMPTAFLOW
            </button>
        </div>
        {{-- Le trousseau se posait à la création de l'entreprise, et jamais
             plus. Une entreprise créée avant qu'un journal entre au référentiel
             — le mobile money, par exemple — ne l'obtenait plus par aucun
             chemin : il fallait le ressaisir à la main, avec son compte. --}}
        <form method="POST" action="{{ route('admin.tresorerie.poser_journaux_defaut') }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-outline" title="Ajoute ce qui manque, sans toucher à vos journaux">
                <i class="fas fa-wand-magic-sparkles"></i> Journaux par défaut
            </button>
        </form>
        <button id="btn-nouveau-journal" type="button" class="btn btn-primary" data-modal-open="modalNouveauJournal">
            <i class="fas fa-plus-circle"></i> Nouveau code
        </button>
    </div>
</div>

<div id="section-local">
    <div class="card">
        <div class="table-wrap">
            @if($codes->isEmpty())
            <div style="padding:48px; text-align:center; color:var(--text-3);">
                <i class="fas fa-book-open" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                Aucun code journal configuré pour le moment.
            </div>
            @else
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Intitulé</th>
                        <th>Compte</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($codes as $code)
                    <tr>
                        <td>
                            @if($code->type === 'Vente')
                                <span class="badge badge-info"><i class="fas fa-cash-register"></i> Vente</span>
                            @elseif($code->type === 'Achat')
                                <span class="badge badge-warning"><i class="fas fa-cart-shopping"></i> Achat</span>
                            @elseif($code->type === 'Caisse')
                                <span class="badge badge-success"><i class="fas fa-wallet"></i> Caisse</span>
                            @elseif($code->type === 'Banque')
                                <span class="badge badge-purple"><i class="fas fa-building-columns"></i> Banque</span>
                            @else
                                <span class="badge badge-gray"><i class="fas fa-folder"></i> {{ $code->type }}</span>
                            @endif
                        </td>
                        <td style="font-weight:700; font-family:monospace; font-size:13px; color:var(--primary);">
                            {{ $code->code }}
                            @if(!empty($code->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $code->numero_original }}
                                </div>
                            @endif
                        </td>
                        <td style="font-weight:500;">
                            {{ $code->intitule }}
                            @if(isset($code->source) && $code->source === 'comptaflow')
                                <span style="display:inline-flex; align-items:center; gap:4px; background:#dbeafe; color:#1d4ed8; padding:2px 7px; border-radius:20px; font-size:10px; font-weight:700; margin-left:6px; vertical-align:middle;">
                                    <i class="fas fa-sync" style="font-size:8px;"></i> COMPTAFLOW
                                </span>
                            @endif
                        </td>
                        {{-- Le compte de contrepartie ne concerne que la trésorerie :
                             c'est le 521 de la banque ou le 571 de la caisse. Un
                             journal de ventes n'en a pas — sa contrepartie est le
                             tiers de la pièce, et il change à chaque écriture. --}}
                        <td style="font-family:monospace; font-size:13px;">
                            @if($code->compte)
                                {{ $code->compte }}
                            @else
                                <span style="color:var(--text-3); font-family:inherit;" title="Seuls les journaux de caisse et de banque portent un compte de contrepartie.">—</span>
                            @endif
                        </td>
                        <td>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <button type="button" class="btn btn-outline btn-sm" style="padding:5px 10px;"
                                        onclick="ouvrirModificationJournal({{ $code->id }})">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                                <form method="POST" action="{{ route('admin.tresorerie.supprimer_code_journal', $code) }}" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce code journal ?')" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm" style="padding: 5px 10px;">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </form>
                            </div>

                            {{-- Le formulaire de modification, replié sous la ligne.
                                 Ni le code ni le type ne s'y changent : le code est
                                 la clé sous laquelle les écritures sont rangées, et
                                 changer le type retirerait son compte au journal. --}}
                            <div id="modif-journal-{{ $code->id }}" style="display:none; margin-top:10px; padding:12px; background:var(--bg3); border-radius:8px; text-align:left;">
                                <form method="POST" action="{{ route('admin.tresorerie.modifier_code_journal', $code) }}">
                                    @csrf
                                    @method('PUT')
                                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                                        <div class="form-group" style="margin:0; flex:1; min-width:180px;">
                                            <label class="form-label" style="font-size:11px;">Intitulé</label>
                                            <input type="text" name="intitule" class="form-control" value="{{ $code->intitule }}" required maxlength="255">
                                        </div>
                                        @if(\App\Modules\Admin\Modeles\CodeJournal::porteUnCompteDeTresorerie($code->type))
                                        <div class="form-group" style="margin:0; width:160px;">
                                            <label class="form-label" style="font-size:11px;">Compte mouvementé</label>
                                            <input type="text" name="compte" class="form-control" value="{{ $code->compte }}" required maxlength="50" style="font-family:monospace;">
                                        </div>
                                        @endif
                                        <button type="submit" class="btn btn-primary btn-sm" style="padding:7px 14px;">
                                            <i class="fas fa-check"></i> Enregistrer
                                        </button>
                                        <button type="button" class="btn btn-outline btn-sm" style="padding:7px 14px;"
                                                onclick="ouvrirModificationJournal({{ $code->id }})">Annuler</button>
                                    </div>
                                    <small style="color:var(--text-3); font-size:11px; display:block; margin-top:6px;">
                                        Le code <strong>{{ $code->code }}</strong> et le type <strong>{{ $code->type }}</strong> ne se changent pas :
                                        les écritures déjà passées y sont rangées.
                                    </small>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</div>

<div id="section-comptaflow" style="display:none;">
    @if(empty($entreprise->comptaflow_sync_key))
        <div class="card" style="padding: 30px; text-align: center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--danger); margin-bottom: 20px; opacity: 0.8;"></i>
            <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 10px;">Liaison COMPTAFLOW non active</h2>
            <div style="max-width: 500px; margin: 0 auto; padding: 20px; border-radius: 8px; background: #fff5f5; border: 1px solid #feb2b2; margin-top: 15px;">
                <p style="font-size: 13px; color: #4a5568; line-height: 1.5;">
                    Veuillez configurer votre clé de synchronisation COMPTAFLOW dans les paramètres de l'entreprise pour activer la liaison et le déversement automatique des codes journaux.
                </p>
                <a href="{{ route('admin.entreprise.parametres') }}" class="btn btn-primary" style="margin-top: 15px; display: inline-flex;">
                    <i class="fas fa-cog"></i> Configurer la liaison
                </a>
            </div>
        </div>
    @else
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <p style="color: var(--text-2); font-size: 14px; margin-bottom: 0;">
                Votre application Selflow est reliée avec succès à <strong>COMPTAFLOW</strong>. Les codes journaux ci-dessous sont importés directement de COMPTAFLOW.
            </p>
            <button type="button" id="btn-sync-action" onclick="lancerSyncComptaflow()" class="btn btn-primary" style="font-weight: 700;">
                <i class="fas fa-sync-alt" id="sync-icon"></i> Synchroniser avec COMPTAFLOW
            </button>
        </div>

        <div id="sync-feedback" style="display:none; margin-bottom: 20px; padding: 12px; border-radius: 6px; font-weight: 600; font-size: 13px;"></div>

        <div class="card">
            <div class="table-wrap">
                @if($codesComptaflow->isEmpty())
                <div style="padding:48px; text-align:center; color:var(--text-3);">
                    <i class="fas fa-sync" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                    Aucun code journal COMPTAFLOW synchronisé. Cliquez sur le bouton ci-dessus pour synchroniser.
                </div>
                @else
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Code</th>
                            <th>Intitulé</th>
                            <th>Compte</th>
                            <th>Origine</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($codesComptaflow as $code)
                        <tr>
                            <td>
                                @if($code->type === 'Vente')
                                    <span class="badge badge-info"><i class="fas fa-cash-register"></i> Vente</span>
                                @elseif($code->type === 'Achat')
                                    <span class="badge badge-warning"><i class="fas fa-cart-shopping"></i> Achat</span>
                                @elseif($code->type === 'Caisse')
                                    <span class="badge badge-success"><i class="fas fa-wallet"></i> Caisse</span>
                                @elseif($code->type === 'Banque')
                                    <span class="badge badge-purple"><i class="fas fa-building-columns"></i> Banque</span>
                                @else
                                    <span class="badge badge-gray"><i class="fas fa-folder"></i> {{ $code->type }}</span>
                                @endif
                            </td>
                            <td style="font-weight:700; font-family:monospace; font-size:13px; color:var(--primary);">
                                {{ $code->code }}
                                @if(!empty($code->numero_original))
                                    <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $code->numero_original }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-weight:500;">{{ $code->intitule }}</td>
                            <td style="font-family:monospace; font-size:13px;">{{ $code->compte }}</td>
                            <td>
                                <span class="badge badge-blue"><i class="fas fa-cloud"></i> COMPTAFLOW</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
    @endif
</div>

<!-- Modal Nouveau Code Journal -->
<div class="modal-overlay" id="modalNouveauJournal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Nouveau Code Journal</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.tresorerie.creer_code_journal') }}">
            @csrf
            <div class="form-group">
                <label class="form-label">Type de journal <span style="color:var(--danger)">*</span></label>
                {{-- La liste vivait ici, écrite en dur ; elle vit maintenant sur
                     le modèle, où la règle de validation la lit aussi. --}}
                <select name="type" id="type-journal" class="form-control" required
                        onchange="ajusterCompteJournal()">
                    @foreach(\App\Modules\Admin\Modeles\CodeJournal::TYPES as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Code <span style="color:var(--danger)">*</span></label>
                <input type="text" name="code" class="form-control" placeholder="Ex: VT, CAI, BQ" required maxlength="10">
            </div>

            <div class="form-group">
                <label class="form-label">Intitulé <span style="color:var(--danger)">*</span></label>
                <input type="text" name="intitule" class="form-control" placeholder="Ex: Journal de Caisse" required>
            </div>

            {{-- Le compte de contrepartie ne concerne que les journaux de
                 trésorerie : le 521 de la banque, le 571 de la caisse — celui
                 que chaque écriture du journal mouvemente. Un journal de ventes
                 ou d'achats n'en a pas : sa contrepartie est le tiers de la
                 pièce, et elle change à chaque écriture. Le champ était pourtant
                 exigé pour les cinq types, et il fallait donc inventer une
                 valeur pour créer un journal de ventes. --}}
            <div class="form-group" id="groupe-compte-journal" style="display:none;">
                <label class="form-label">Compte comptable <span style="color:var(--danger)">*</span></label>
                <input type="text" name="compte" id="compte-journal" class="form-control"
                       placeholder="Ex: 521000 pour une banque, 571000 pour une caisse">
                <small style="color:var(--text-3);font-size:11px;">
                    Le compte que ce journal mouvemente à chaque écriture.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>

        <script>
            // La liste vient du modèle : la règle de validation du contrôleur
            // lit la même, et les deux ne peuvent plus diverger.
            var TYPES_AVEC_COMPTE = @js(array_values(array_filter(
                \App\Modules\Admin\Modeles\CodeJournal::TYPES,
                fn ($t) => \App\Modules\Admin\Modeles\CodeJournal::porteUnCompteDeTresorerie($t)
            )));

            function ajusterCompteJournal() {
                var type = document.getElementById('type-journal');
                var groupe = document.getElementById('groupe-compte-journal');
                var champ = document.getElementById('compte-journal');
                if (!type || !groupe || !champ) return;

                var concerne = TYPES_AVEC_COMPTE.indexOf(type.value) !== -1;
                groupe.style.display = concerne ? '' : 'none';
                // `required` sur un champ caché bloque l'envoi du formulaire
                // sans rien afficher : le navigateur refuse de pointer un
                // champ qu'il ne peut pas montrer.
                champ.required = concerne;
                if (!concerne) champ.value = '';
            }

            ajusterCompteJournal();
        </script>
    </div>
</div>

<script>
function switchVue(mode) {
    const btnLocal = document.getElementById('btn-vue-local');
    const btnComptaflow = document.getElementById('btn-vue-comptaflow');
    const secLocal = document.getElementById('section-local');
    const secComptaflow = document.getElementById('section-comptaflow');
    const btnNouveau = document.getElementById('btn-nouveau-journal');

    if (mode === 'local') {
        btnLocal.className = "btn btn-sm btn-primary";
        btnLocal.style.color = "#fff";
        btnLocal.style.background = "";
        btnComptaflow.className = "btn btn-sm btn-outline";
        btnComptaflow.style.color = "var(--text-2)";
        secLocal.style.display = "block";
        secComptaflow.style.display = "none";
        if(btnNouveau) btnNouveau.style.display = "inline-flex";
    } else {
        btnComptaflow.className = "btn btn-sm btn-primary";
        btnComptaflow.style.color = "#fff";
        btnComptaflow.style.background = "";
        btnLocal.className = "btn btn-sm btn-outline";
        btnLocal.style.color = "var(--text-2)";
        secLocal.style.display = "none";
        secComptaflow.style.display = "block";
        if(btnNouveau) btnNouveau.style.display = "none";
    }
}

function lancerSyncComptaflow() {
    const btn = document.getElementById('btn-sync-action');
    const icon = document.getElementById('sync-icon');
    const feedback = document.getElementById('sync-feedback');

    btn.disabled = true;
    if (icon) icon.classList.add('fa-spin');
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Synchronisation en cours...';

    fetch("{{ route('admin.entreprise.comptaflow.sync_real') }}", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": "{{ csrf_token() }}"
        }
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (icon) icon.classList.remove('fa-spin');
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser avec COMPTAFLOW';
        
        feedback.style.display = "block";
        if (data.success) {
            feedback.style.background = "#e6fffa";
            feedback.style.color = "#319795";
            feedback.style.border = "1px solid #b2f5ea";
            feedback.innerText = data.message;
            setTimeout(() => {
                window.location.reload();
            }, 1200);
        } else {
            feedback.style.background = "#fff5f5";
            feedback.style.color = "#e53e3e";
            feedback.style.border = "1px solid #fed7d7";
            feedback.innerText = "Erreur : " + data.message;
        }
    })
    .catch(err => {
        btn.disabled = false;
        if (icon) icon.classList.remove('fa-spin');
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser avec COMPTAFLOW';
        feedback.style.display = "block";
        feedback.style.background = "#fff5f5";
        feedback.style.color = "#e53e3e";
        feedback.style.border = "1px solid #fed7d7";
        feedback.innerText = "Erreur de connexion.";
    });
}
</script>
<script>
/** Ouvre ou referme le formulaire de modification d'un journal. */
function ouvrirModificationJournal(id) {
    var bloc = document.getElementById('modif-journal-' + id);
    if (!bloc) return;
    bloc.style.display = bloc.style.display === 'none' ? 'block' : 'none';
}
</script>

@endsection
