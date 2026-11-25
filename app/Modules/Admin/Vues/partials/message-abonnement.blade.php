{{--
    Le message de DC-Knowing à l'entreprise (25/11/2026) : un bandeau qui se
    déroule, en haut de chaque page, et repris dans la fenêtre de blocage.
    Variable : $texte ; $dansLaFenetre (bool) pour la version de la fenêtre.
--}}
@if(filled($texte ?? null))
    @if(!empty($dansLaFenetre))
        <details open style="text-align:left; margin:0 0 18px; border:1px solid #FECACA; background:#FEF2F2; border-radius:10px; padding:10px 12px;">
            <summary style="cursor:pointer; font-weight:700; color:#991B1B; font-size:13.5px;"><i class="fas fa-envelope-open-text"></i> Message de DC-Knowing</summary>
            <p style="margin:8px 0 0; color:#7F1D1D; font-size:13.5px; line-height:1.6; white-space:pre-line;">{{ $texte }}</p>
        </details>
    @else
        <details id="message-abonnement" open style="margin:0 0 14px; border:1px solid #FDE68A; background:#FFFBEB; border-radius:12px; padding:10px 14px;">
            <summary style="cursor:pointer; font-weight:700; color:#92400E; font-size:13.5px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-envelope-open-text"></i> Message de DC-Knowing
                <span style="font-weight:500; font-size:12px; color:#B45309;">(cliquer pour replier)</span>
            </summary>
            <p style="margin:8px 0 2px; color:#78350F; font-size:13.5px; line-height:1.6; white-space:pre-line;">{{ $texte }}</p>
            @if(auth()->user()?->role === 'admin')
                <a href="{{ route('admin.entreprise.parametres') }}#facturation" style="font-size:12.5px; font-weight:700; color:#92400E;">Aller à la facturation <i class="fas fa-arrow-right"></i></a>
            @endif
        </details>
    @endif
@endif
