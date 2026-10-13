@php
    $approvedBy = trim((string) ($approvedBy ?? ''));
    $approvedAt = trim((string) ($approvedAt ?? ''));
    $leftLabel = $leftLabel ?? 'Approved by';
    $noteLabel = $noteLabel ?? 'Approval Note';
    $note = trim((string) ($note ?? ''));
    $signLabel = $signLabel ?? 'Authorized Signatory';
    $hasApproval = $approvedBy !== '' || $approvedAt !== '' || $note !== '';
@endphp
@if($hasApproval || ! empty($signLabel))
    <table class="sign">
        <tr>
            <td>
                @if($approvedBy !== '')
                    <div class="k">{{ $leftLabel }}</div>
                    <div class="v">{{ $approvedBy }}</div>
                @endif
                @if($approvedAt !== '')
                    <div class="k">Approved on</div>
                    <div class="v">{{ $approvedAt }}</div>
                @endif
                @if($note !== '')
                    <div class="k">{{ $noteLabel }}</div>
                    <div class="prose">{{ $note }}</div>
                @endif
            </td>
            <td>
                @if(! empty($signLabel))
                    <div class="sign-line">{{ $signLabel }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif
