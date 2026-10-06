{{--
    Le compte sur la plateforme FNE, à la création d'une entreprise.

    Une seule question décide de la suite : **l'entreprise a-t-elle déjà un
    compte FNE ?**

    - **Oui.** Tout est déjà dans son espace : sa situation fiscale, ses
      établissements, ses options. Il suffit de son NCC et du mot de passe de
      cet espace pour relever le paramétrage — inutile de lui faire ressaisir
      ce que la DGI détient déjà, avec le risque d'un écart entre les deux.
    - **Non.** Il faut lui en ouvrir un, et la DGI exige alors des informations
      précises. Ce sont celles-là qu'on demande.

    **Aucune information fiscale n'est demandée avant cette question** — le
    régime d'imposition compris, qui était réclamé à la première étape des deux
    écrans de création. Le faire ressaisir à une entreprise dont l'espace FNE le
    porte déjà, c'est ouvrir un écart entre les deux.

    Ce partiel est inclus par `/inscription` **et** par l'écran de création du
    superadministrateur : la question se pose de la même façon des deux côtés.

    Le mot de passe est chiffré au repos et n'est jamais rendu à l'écran. Il
    sert au paramétrage, puis s'efface : ce qui ne sert plus ne se garde pas.

    @param  string  $prefixeId  pour ne pas collisionner si la page inclut
                                deux fois le bloc.
--}}
@php
    $prefixeId = $prefixeId ?? 'fne';
    $aDeja = old('possede_compte_fne');
@endphp

<div class="bloc-fne" data-bloc-fne>
    <p class="bloc-fne-intro">
        Selflow établit vos factures, la plateforme de la Direction Générale des
        Impôts les certifie. Dites-nous où vous en êtes — vous pourrez revenir
        sur ce point plus tard.
    </p>

    <div class="fne-choix">
        <label class="fne-option">
            <input type="radio" name="possede_compte_fne" value="1"
                   id="{{ $prefixeId }}-oui"
                   {{ $aDeja === '1' ? 'checked' : '' }}
                   data-fne-radio="oui">
            <span>
                <strong>J'ai déjà un compte FNE</strong>
                <small>Votre espace contient déjà tout : nous le relevons pour vous.</small>
            </span>
        </label>

        <label class="fne-option">
            <input type="radio" name="possede_compte_fne" value="0"
                   id="{{ $prefixeId }}-non"
                   {{ $aDeja === '0' ? 'checked' : '' }}
                   data-fne-radio="non">
            <span>
                <strong>Je n'en ai pas encore</strong>
                <small>Nous nous chargeons de l'inscription : donnez-nous vos informations.</small>
            </span>
        </label>
    </div>

    {{-- ── Elle a un compte : son accès suffit ── --}}
    <div class="fne-volet" data-fne-volet="oui" style="display:{{ $aDeja === '1' ? 'block' : 'none' }};">
        <div class="fne-note">
            <i class="fa-solid fa-shield-halved"></i>
            Ces informations servent uniquement à relever votre paramétrage.
            Le mot de passe est <strong>chiffré</strong>, n'est affiché à
            personne, et sera effacé une fois la configuration faite.
        </div>

        <div class="rangee-2">
            <div class="champ">
                <label for="{{ $prefixeId }}-ncc">N° de Compte Contribuable (NCC)</label>
                <input type="text" id="{{ $prefixeId }}-ncc" name="fne_ncc"
                       placeholder="Ex : 2603210A" maxlength="8"
                       value="{{ old('fne_ncc') }}"
                       style="text-transform:uppercase;">
                @error('fne_ncc') <small class="erreur">{{ $message }}</small> @enderror
            </div>

            <div class="champ">
                <label for="{{ $prefixeId }}-mdp">Mot de passe de l'espace FNE</label>
                <input type="password" id="{{ $prefixeId }}-mdp" name="fne_mot_de_passe"
                       placeholder="Celui de votre espace DGI" autocomplete="new-password">
                @error('fne_mot_de_passe') <small class="erreur">{{ $message }}</small> @enderror
            </div>
        </div>
    </div>

    {{-- ── Elle n'en a pas : ce que la DGI exige pour en ouvrir un ── --}}
    <div class="fne-volet" data-fne-volet="non" style="display:{{ $aDeja === '0' ? 'block' : 'none' }};">
        <div class="fne-note">
            <i class="fa-solid fa-circle-info"></i>
            Voici ce que la DGI demande pour ouvrir un compte. Ce qui vous
            manque aujourd'hui se complétera depuis vos paramètres.
        </div>

        <div class="rangee-2">
            <div class="champ">
                <label for="{{ $prefixeId }}-rccm">N° RCCM</label>
                <input type="text" id="{{ $prefixeId }}-rccm" name="rccm"
                       placeholder="CI-ABJ-2021-B-12345" value="{{ old('rccm') }}">
                @error('rccm') <small class="erreur">{{ $message }}</small> @enderror
            </div>
            <div class="champ">
                <label for="{{ $prefixeId }}-ncc2">N° de Compte Contribuable (NCC)</label>
                <input type="text" id="{{ $prefixeId }}-ncc2" name="ncc"
                       placeholder="Ex : 2603210A" maxlength="8"
                       value="{{ old('ncc') }}" style="text-transform:uppercase;">
                @error('ncc') <small class="erreur">{{ $message }}</small> @enderror
            </div>
        </div>

        <div class="rangee-2">
            <div class="champ">
                <label for="{{ $prefixeId }}-regime">Régime d'imposition</label>
                <select id="{{ $prefixeId }}-regime" name="regime_imposition" data-fne-regime>
                    <option value="">— Choisir —</option>
                    @foreach(\App\Modules\Admin\Modeles\Entreprise::REGIMES_IMPOSITION as $code => $libelle)
                        <option value="{{ $code }}" {{ old('regime_imposition') === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                    @endforeach
                </select>
                <small class="fne-regime-notice" data-fne-regime-notice hidden></small>
                @error('regime_imposition') <small class="erreur">{{ $message }}</small> @enderror
            </div>
            <div class="champ">
                <label for="{{ $prefixeId }}-centre">Centre des impôts</label>
                <input type="text" id="{{ $prefixeId }}-centre" name="centre_impots"
                       placeholder="Celui dont vous dépendez" value="{{ old('centre_impots') }}">
            </div>
        </div>

        <div class="champ">
            <label for="{{ $prefixeId }}-adresse">Adresse de l'établissement</label>
            <input type="text" id="{{ $prefixeId }}-adresse" name="adresse"
                   placeholder="Ex : Cocody Cité des Cadres, Abidjan" value="{{ old('adresse') }}">
        </div>
    </div>
</div>

<style>
    .bloc-fne-intro { font-size:12.5px; color:#6B7280; line-height:1.65; margin-bottom:14px; }
    .fne-choix { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:4px; }
    @media (max-width:640px) { .fne-choix { grid-template-columns:1fr; } }
    .fne-option { display:flex; align-items:flex-start; gap:10px; padding:12px 14px;
                  border:1px solid #E5E7EB; border-radius:10px; cursor:pointer;
                  background:#fff; transition:border-color .15s, background .15s; }
    .fne-option:hover { border-color:#002B5C; background:#F8FAFF; }
    .fne-option input { margin-top:3px; flex-shrink:0; accent-color:#002B5C; }
    .fne-option strong { display:block; font-size:13px; color:#111827; font-weight:600; }
    .fne-option small { display:block; font-size:11.5px; color:#9CA3AF; margin-top:2px; line-height:1.5; }
    .fne-volet { margin-top:14px; }
    .fne-note { display:flex; gap:9px; align-items:flex-start; font-size:12px; line-height:1.6;
                color:#1E40AF; background:#EFF6FF; border:1px solid #BFDBFE;
                border-radius:8px; padding:11px 13px; margin-bottom:14px; }
    .fne-note i { flex-shrink:0; margin-top:1px; font-size:14px; }
    .bloc-fne .erreur { color:#DC2626; font-size:11.5px; display:block; margin-top:4px; }
    .fne-regime-notice { display:block; font-size:11.5px; color:#6B7280; line-height:1.55; margin-top:5px; }
</style>

<script>
(function () {
    // Les définitions viennent du modèle : elles vivaient dans le JavaScript de
    // l'inscription, pour quatre régimes sur six, et le second écran n'en
    // affichait aucune.
    var NOTICES = @js(\App\Modules\Admin\Modeles\Entreprise::REGIMES_NOTICES);

    // Un volet caché ne doit pas être ouvert par défaut : demander le mot de
    // passe d'un espace FNE à qui n'en a pas serait absurde, et demander un
    // RCCM à qui a déjà un compte le ferait ressaisir ce que la DGI détient.
    document.querySelectorAll('[data-bloc-fne]').forEach(function (bloc) {
        var volets = bloc.querySelectorAll('[data-fne-volet]');

        bloc.querySelectorAll('[data-fne-radio]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                volets.forEach(function (volet) {
                    volet.style.display =
                        volet.dataset.fneVolet === radio.dataset.fneRadio ? 'block' : 'none';
                });
            });
        });

        var regime = bloc.querySelector('[data-fne-regime]');
        var notice = bloc.querySelector('[data-fne-regime-notice]');
        if (!regime || !notice) return;

        function afficherLaNotice() {
            notice.textContent = NOTICES[regime.value] || '';
            notice.hidden = !notice.textContent;
        }

        regime.addEventListener('change', afficherLaNotice);
        afficherLaNotice();
    });
})();
</script>
