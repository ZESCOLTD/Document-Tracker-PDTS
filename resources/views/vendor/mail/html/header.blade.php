@props(['url'])
<tr>
<td class="header" style="padding: 20px 0; text-align: center; background-color: {{ config('theme.color_primary', '#253745') }}; border-bottom: 4px solid {{ config('theme.color_accent', '#f59d33') }};">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none; color: #ffffff;">
<span style="display: inline-block; background: #ffffff; padding: 5px 8px; border-radius: 3px;">
<img src="{{ asset('assets-dashboard/dist/img/logo/ZESCO_removebg.png') }}" alt="ZESCO" width="118" style="display: block; width: 118px; height: auto;">
</span>
<span style="display: block; margin-top: 10px; font-size: 22px; font-weight: 700; color: #ffffff;">
PDTS
</span>
<span style="display: block; font-size: 12px; color: {{ config('theme.color_accent', '#f59d33') }}; margin-top: 2px;">
ZESCO Physical Document Tracking System
</span>
</a>
</td>
</tr>
