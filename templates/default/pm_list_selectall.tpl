{IF NOT MESSAGECOUNT 0}{IF NOT MESSAGECOUNT 1}
{ASSIGN DID_TOGGLEBLOCK 1}
<script type="text/javascript">
document.write(
    '<input type="checkbox" name="toggle" onclick="' +
        'lf=document.getElementById(\'phorum_listform\');' +
        'for (var i=0;i<lf.elements.length;i++) {' +
            'elt=lf.elements[i];' +
            'if (elt.type==\'checkbox\' && elt.name!=\'toggle\') {' +
                'elt.checked = this.checked;' +
            '}' +
        '}' +
    '">');
</script>
<noscript>&nbsp;</noscript>
{/IF}{/IF}
{IF NOT DID_TOGGLEBLOCK}&nbsp;{/IF}
