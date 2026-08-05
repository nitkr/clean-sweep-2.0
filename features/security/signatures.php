<?php
/**
 * Clean Sweep - Malware Signatures
 *
 * Comprehensive malware signature definitions for WordPress threat detection
 * Updated for advanced obfuscation techniques and multi-layer encoding
 */

/**
 * Malware Signature Manager Class
 * Handles loading and managing comprehensive threat signatures
 */
class Clean_Sweep_Malware_Signatures {

    private $signatures = [];

    /**
     * Initialize with default signatures
     */
    public function __construct() {
        $this->load_default_signatures();
    }

    /**
     * Load comprehensive malware signatures
     */
    private function load_default_signatures() {
        // Enhanced malware patterns for sophisticated detection (obfuscated for security)
        $encoded = 'eJzNPAtX27jSf4Xl9pwmgaQJr5bSlqWQtuxSYKHtPpLg69hK4uLX+pGQIv77NyPJsuTYCe3ee757Tkv8GI1mRtK8NHJvvf+MTE23348b/X4Nf3o3T/vrcDHoP1vfXHxdO3w5NGOyt2PYxApsQsffHH/kmgmh/f4TA16/736ilxfXn+hV97fPXfg9vrj49bRbp4iJxvM4IR4ld8Si8YS4rsEuQzOOk0mUUvglUUL/Xe8/c1j/OZBCxYqulrZdwkGcREYUJJ1tmkYuf1uNq3Xf3txtPxz2+/XeWr+fDBBzv9/C1/VDuF7gVzLp+JabQs8R+Tt1oryPMAosIwiJ//2sljX9QU6LqP4bjP4gk/8hBv8Rc2WztsAnHTkuMcYkMazAT4ifxLJvAVngHRhfDoBSySBEJ0bgW0UwIbcMcoEMHbzfn220GpMkCWWDgoB7oAsGKgDXBrWe2fx21Pyr3dzf6D97Pbjfarc3HzKY6yRy/DHIcBQF3vHEjI5B+K2GHVipB0TACysIbh2SwTNKavnrOkBExLTnrYYbWGbiBEBSaxKRUatRGzqIwJ3TIA3gwgloGET+JB1SK5qHSSAFrXQ3i5yE4Fg3XsVW5IQJ4EkiczRyrK+pfzuHcQ0s17Fu6xpJLZDctYCHu3AS4u+hY7/OwMwoMucGSDkhUVG0Bko3A0zNOIiSpSAc18x0bx8B5pnhUii5DhQobaXkgKlvBV4YkTheBas/zIHlUpOqUj5Y2btNKsgkvgpYMEKr2VtCXQFXVbuyPlbhKmP80UNRwCXV1nfRumSoev31p4MerFpYu2zh9heX7upOHz3BFCWCHatKBGbbGKZwYk0UKK6IcIXVW9UCgpWb6/gK3V9HJBnmMoG0GqoYUAidXUUIBVjEBdapaJukrqiVGJ0qFgqoa+/PLt4enV2D7iLQ3hjBYkR1J7wg2cUFWCR742V/vXcD+rjRXxf3/ftWg0ultLfjJc36fcmvqT1Gk7AggVyzzEJDEJsCia2GaXuOLzHZNvDvEvHYAXmYSRApbWMwRkGIPLYanunOzIgobz3TUZcAyF1DDxCOj4IxwiBOtLkDuBzLCdK4Wv1zc89NmrADGXDBzCDsa2n+bDMxX9YScpfQSeK5deYgD4SM+M19R11EhYUMEhhKAeG1WI439QGf7AxeWn+fRB8+fTzjULBID19zDg9r3MBRK3D8iTMl1AsANqAzMgQZsclnJqkHjRIrrD8GYYFtOnN8O5iheRUvqCLF7FkdWy6xwqYX07u7u0fZ4d7Nm0EjjqzXiLPUIgPe0LRj6o0d+xE81RacDCAosqk1nVLL8lMPXLZ5aLr1ZWOla45Sb0bVbqBEYAJs7jwIj5QRVHxev9/dfEAumTu5SCWsi0+OR4I0yZ0eeOjbb4kJzpuk1zCSgFOkz5sU14UDi+CbShujZplnTa+7V1+6V/UlqhQ9Y6nqDuN6ZU9VnrjqYpS55avixjq66B300WvfE0EWVeq/FS5e/RQMvxIraVqmNSHct6vjY8uF1s3M3WOPuAYCWwmyKOddeNL8UnUhZ6HNZlPzDf79OyXRXFNadhQwg1fK/uMRMft6cnVxCQQCffGPNOQ6+Yeaoi5+fEPeJhPZ97ZqZfLOLUKVWBNz6JLcMMEggk8JwCkxuPKJK6zmSLUXor/ezeZgY/NRS6pyLS0GhVSEfjSL8KgSw31nuqR2+FOBZPBvjAHMyt5T0d/Tfn9AdaDQjEBfF2Dqj5MEM47CGNZmYRMwjJwxXzkUTUpiWhauebh/iQsI/1P8gwkGdmPh8z43O7G2PvlC5GsyQB+BXXqBnbokFnd88eK6LCNUi4HDtDQGBhb6gon+SjY0yjWeimzwfuPAui2G07nwwPqjWmujWkPbiTaTWp7NR5kPfnF65LkolxA9ALQz9VMwC4+GzDIdfKbxWfd47VpXuvgaB/5iEFAVHDwy8/NfJU/0sfgGR2mDJSTiH0oLZW683k+ZDXmMNecizGCCoREnZpRUIoEgCxxwEhkJ+FFSFOWLBLh2wRUHrQmwsaJdI+IFCdNaqyLCHHjBOV8SooxlPKC14LAt1Z6moY1DugRYZd4LphChhG5g2sTGDE0+pOoSRJMCg4lLPUTnHtc1c9wwowc3ZlRfiVGZxe9Oz7rXdMGSF/oEQK5nUB1RjjKmiRcyFTM1I/yZzWaru16ZvCzmJcU9Sx1SNY9YV4ZxYvq2m3WoMLlkGKEVBzcgMohLRmdhUsaOTYZmVI1v5tg4OaI41kfWnvum51g5AvUltkgmxCPgZAbJkjkHyzZEo2zYIAELYtS5juj3S+Py7PP703Pj5PSqyETopmNwJ6CpEZrJRG/JXI2m+dW8y3KGVRwijFFsbBtmcZ0yQyvgYUwNPwgjZ1o/NITxZe9lbFGVHlmlq/7NzAxluZmIgHAsjPyfaTODUQwkcAoMNsXAzxixUOfF5oMwXmjG+HQAGqxbOwgibtBAHEBcQIdz7JbCAKZjVaWrfcVg3NHaG2QKy6RailaEuQRViHFoznzxXB84kYCw0igCnCKDUYU4dsZ+ETXKIIUJ5oOezHMgC50oWYplC4YpNA5UnAT41PBIYhax48t4EkQJT81oKyNQ35R2K98bZpLEumbOlyh0bYYOcOFUkQ/L4wo0m3EFKoQU6R+Bw2ZquT2WtC7O6HwSignMfrL5qD3LNqTYnRqMarSCC9xqyIFjYeN2GxwsMeo4cAYPt0V+yoAYVMcmlBNDG0EwzpJfuSst0kKF9Fghh6A3KETPpV3AKlJWpOriyNUpRVB0JhakwEWg8wvA8XISdJacXOeuaPeDpMeFlWIFnle9ysVbAyaWt7geXTeYFREUnAZcpmI1Fa2F8qq0b1xvheYLSxIU9pQtZWYXKhDZpAAXL7CiJVbLvJp45iTWxEgCY+gG42rVIqQaO0lBRfgkmQXRrSGnf5EXZsMx12kHnukUtJ9hsOTdQnYYnmcQPL3HpQRT3yhmdytp/ghOR+S4bsGNU5osiCs2R2DnCbfg1cLIIQrtbYeUaVe+nQeu8IRkMWOZio8NLxg6LimfE0dvry+PPn2oVp/HF+efuuefuHux0vMoSGUe/+06PPOhv7k8uVgYH54gUZtmLctwQ6RM/Gm158Reqg2eGN3zL+W4QBOKDY6qHZ27JDLzsasMglr5blhR0ArMijBDunwhRE16VyzjSiREBRKxNnSYBRPM3bcqbRKGbrZhrCUiMNBU7ciCP1jeH0ZcEBtkIGUzseDQQWhRxV8ppKZ+MHmbvV8i7TgdVoKqifdWAzeAl+yBcnsvrCj8HPBrnnZB75S3egfLMAYFki/PJvoA3P+WRgQzSsYs9MldCmsXTKUC7rbHsQ4OD0VmUX36PSFVSb5vpJrBLBa8oSIxRnt9VnhVj0zbCXjH6GCzpwf1AX1Sz5XEl+MTXZQArCu+5tSyF3iS2WwL9/vRtLpBxK4qGgjaWfKLmy0BzMIsNhargB3fJnfLEDNUjwNV0KLbETwS1rx1Yo8k2qXa8EYVTQ1kQ7/G1PHMMYnrbK5tcHiRMMnBs3KdGOfGT7xHkTvkcTq7TMMxjGl2h5GNZ4qbTJ+IW0EEu/aDIBSXnhNb4pJnV9hlRKZOzPfvON6IEF92iZ5PkyVP1SdZt5Hpxy7bZmqCjBLwosQbnnkFBjn/TbCeWX8u+H9NnlcXZIFP1BwGd0Qsnnrvpv9sIHZPnizMOZBQJi4qPBGKXkYiRI1CB9kjFoAebJRIng+VFDpHenqyTa8dkCO5dAgVMUlMLy33LyekAF9Hh7w5yFHJzHDsqWoD/CJb2QVhosB9y7ApIlcFtPDETUcTI7iVKnDuuY6vlvI8YuOAm9fSfYIW/uPZc5KIFLTIRdfL+8SEwOFLpQE65DNbS2PHxB0xCH/qQLic96NltDGeh8fg3FM/dV0tTz8Mm3kunufoYcKY7Grq2CQo21ZrNQriyRmwRmNVi889mO5O6mXPXvX7h7hsWbufZWFcv4e4ByVQzoiJX4MTCvgeycBQRSuG+Pmw31cNIZMkSCh/L0rgDkvq7fSXSlGf/kIt5vv5kCeJKzFyGktxZiFW6Uu1XrWMHuWFSg9Lg7YamQhzT6530T51O4P73c2t9oMMhfkm0EJREauH0KJ1BUOHJS11FEusu01Gjo/j0F//49fun/11lkntr7+9/esWTWO9DPDL0dnnrgR9vtXZ3rba5vO93a29Ifx22vvbL3b3n2/vjvZG7RdwT/asXbONvy927eedrf09E94Pt0yAe/Hc3H2xN4L3221z70V7f2t7d1jRdbY3iqSyC3yyKR/nohrc7zywR0+WIGFslKEBBGZzxAW9tbu3EtPR5WkJmvadRPR8s9NeieX6w3cjeXt03cVIho9tS7ZiCoNjE5Bnp+e/XhuYQmePWxwO1URsYO0F+O1jIiPNt2cX743zo48aMEbFTZ/MYg1xCR+nn7ofjy7LBMsMRaOJobNnhmUCsVVMGXsSlVQvlP2LG+TOkXVGt0Nbymr3oZdNhPbm9kOrIUMlVL13O8/5j8V/Rvxna9FRxuXSkKh2N1/AyhKPNeWqbABtKOAdXImoIb91Ovae+XxTpLDSnfY22ySE67+H5q61TeSKham/foBvbpTVnZVAvC5O0qPmu8H99uaDGCjGiPpqS+peIQHcjpYUbgNDuCktUR/xXCKQXGcKY+wGQyaLDZF4k80OcuoO3yg2QY6nBVFl8XRA7+YAB2RL35jN6p2Y4ZU1TyXbtXIXH5StXk1837t5GBTipcIILanWloXY8aHcd1ad7/jl9hYW+mXJ+G2Q6jqr1NI2ARidi/4FIlaKfjKkzst9XsW0v/lwUIGMNa0q81dz8DIfoVaCTiF6H+GKakWp9MM+n388uv61e2LUrrrnJ92r7hX9AhcXV3Xj9+7b92clM7phgBbKeN/hM5qtw3v8A3zyHUkVau8BplMvcwVJXozK4jIzdFh4NCGW9OkqSgoXusclmI38uumOA1Z4CR4gek+0vx7d9dfhx/LFT3NCzOk8u3ERs7gOHSvIYPuYzbzbYrfjCQQBEbhXUX9dCvumt6OZl32wL53m/lHzwy/N80t8fus1v4HJeMgcYJjfAbr9cRrCTE/uvIgVpHlUPOcFhnARWMTEwOJu/o36ph80BUAQjSnWHuYti6jqwLkNq5FXwTEVkCtbTHRKxfky1xuP5aPgp8cbrcY119st7t/VbIgHUAEvpJ4ngUdY+l9ZYmjRcVW1BiziluYKaDFQVT5kD7PloUT+LT7JTLZq+bYfo0pSlDU6TuMEbJl4alxGAbrJES0+aDXs/IQEXvZrTxMIa54ypbwCywI1VUaAn+FgdmDrYXEPQ7ze2Fx81ijo+hv828hVu6rXl28IYQPsQFZamLHeHRhysZfzUKjrUJ1LBCylr4Ru1YJnFlSZcS82VRuqewkV2LJ5xXbv4w3fjmeSgCRKwdPpA1fXg8YH0H7HroNqJGvzHcD/UjIcz2pZ6QBusqah+ImplzZF6oNKe7i3+VBHp1pJsDz5l8xpLZhvViPNqnPf8DjSdmIIh+ZyofqgGihmH4YOKKz8+cSxbeLzstEhtLPM2PEDan7Dv1+BwDBI6Nj0hrDyZg3AYI4jk1qYwo1B7dzCHI6C1CUJ7l+TZHtvl3bu4IJOU/fW9CmWvNgmtSMI7G8DzD/QaWDfmqxWwwwmLIESgV0aBoFDp+bUBOgOBLLUIy7ggQk2pBiSvpywPEqtjmZZFJiWMSlev0A36X+WoVcmTjqs4X5d5iiIInVgcut/mQ2YoHGFO5mXGvKKl1op/TrVpWS6QRIfCoYYu7y6GFQL6+NAkjALjRrfRhOJs3qxrknTYbycb7f9ONKU/peQqclDr4+tsdw01QikImNR12na/oc05VRUF6eEEZFFFWyXU+5xyuB+U+TAlKHcYNe0KEc0A+BC9zALOMC7QV3dAjgUiJwRFXaF4k5RQgsb9XgvM8DYvEfV43rSbfrnXC2wpJKrbK5UIANdyRgAORf2IIsEma6rUcAL0pOqIKxYdcF+asLi8oCp2cgyrXXxr8RRyIvba1qEyyMa4fOzaxXyMYjUAdssPtJ6CSK7UI/ZZGJh7gmDsCbR6m6zQze9m3v8vccJCY1hrvj1bP1idZ6BqW6DpbqpInVmLHShj4MkWPSm9pgzdQC4Wo2lEHWMjLORZ5ZYdaDq953d/FzRE9MFbTrkpZi5Xjx6e3zSfff+w+kvv559PL+4/O3q+tPnL7//8edf5tCyyWg8cb7eup4fhH9HcZJOZ+DFtztb2zu7e89f7DeNTPGJToaOL/E/fZo/j0hc9hz3L3wT85PxPDayfVasjeNBWL5C4snIEMsjn2aLxdnZ1Ei8MHeKVnRSmYYoy04aTXHoUWcc2hZYzyzD4fI5jOfD1Al8S+bZMtEDBd4cGWOJi9TXdgg4w3WVnmTixM03eSE/n+0sGdJa+jpHYSluMKYl+FYDJjka/K2KlGlJbRkK/3aghEOvc0Vb1qKkQeH98h6z9qtYaDVmk4UaXKHCedbKVzsXb9jQbSvXHZmJ4I57SbVX6SRiHjq08Af32+Lsn6hpKlAFcbqLm3K1BdX2alEHFpQeclukJlOEAKqrwoUXxeC4ECHJweV9VfMvzAj3JzrIK8zSH2lTppsNg0WqUWrpp+OLcih2sDj9pW4/WJTrf7DJMs2l1nkfsEFgiEu/6FBRIuibU2fMzsz2WzMyBH96Cl6ZPJhpgTG6nEBcH3j5QyMsPskeGIbvjCeJZ0YErmMCM9FJPSP1Z5EZhiQ/UVk7xsOW75w7yi7W8OptFMzAvVz7zLzLtStu5216TcCPgZhv7RiTY2vvTJCETb9gJm++Ng/SNehtbZJ64McCmrU/gzRaE7iYs73DUqt40pJnEN9lJ/RWH0ctPYtJi3m5YuGzPFpJ5cHRnPHCidzc72BDU96hekq0rx0a1fFK1tTzJ1U7Zrtyx6wwJcJgRiKeZg5n8QRxbdSaxLdok38kwT4OPJC2TZvdOxgb7PAygGGcr71lddagDk+7f9CTYOZjvoDzRM9ZDcTvZCgTDLy7laNQL2hGcQKMHdcdwbyxgxTcpqYd+CDxOCGmCzOYJXrnzaGYB7I3ZTOflhWF0NKSjrrYIey3al/Z+TJGE0vUsPDgjaJfeRJHnPdm7ySnKYgNoibb5cenOaUZH/gVkloShBSTnoHPYXB26V8KQRtOomHACgWAkWGZluNAhrbmpSYxxNvYGcc0u/HJXSIqxOWzURAk+iulHCE/qMNLt0QCm22f4bdXeDUn20JL5mFFHbrqJatkZdDtzt7+3vZ2Z+f5nvT/ME/M6oBYNYY4xJ3VXMoEJkDZAbivjh+m6Oe0PKyscmzFzgvDjg4CLwSPEh+FxteE4hH0Op09pmU7nR32u893rTrtrcLvLn+9r0F32h3x29bAiu9lsz2l9xCEMsTyl5oZQZhMvXld40MddSwxJcXj5BPHziI/vquJkQYVdXxKjTDzDTP/MTSwTvKWnfo+vuoefequfTp6e9al6k2rocDJfSHi259hxZ2YWL2cFTzHlJU0uwErV2bXhJfaihSYAd62nCOXH86/Wp47s092xvaWm/71fn/7zLub/rn1rm3+vp+e+b+41vu7yZ9bn+WXcWTQIxUdTPwDvVZegC45Gah6K6rXXVp1WAYvtitr6Dnqe471e/WTH2Ufbvne/n+Ajx9kUfNOUenxQh8tdKSC5edws7kN7O7k3P684sRCxcOciOzYcEmigekORhR+CCRXya/fSGq1b4OI3QPFD2MHGEQFO07NnJ68W24fucctq/iHC0dM2ATXkzXsCHNu5w8We1MlUN6jOGDBCqqY2625D4yJXK8uIO1JQZyeyL2F128k9uUJKcYpy/7lfIkab2M2IZGYea2FrZnTE3zyU8mWzROt6/LU09Qhs5jrDrVftVLKQOcU9yDwayx30LidCdxDnS3iPRardFRpVvOL3koT+1SKENXeeQ2/0oodUJDNT32Y5o69hhjWkP3WI/rkJyPwII3aU1Y+JWwTbyotE47fa+WDMeBnJ0Rk6P4ZjY/LA+ZECsOCpxLl6hTPUKEwra6SgtU2SJCImnlmS18BWQq1V9YBt4xxhQn//eLq5PKqe31tHJ18PD03Pl93rzKhcbODBBnkzomLn6aTHyZg8Lm1UmWuTiJdxrz3yzXW7Rp2u9b94/T603Wpg52fQabaIeMFr92QC9CxyzCp71uNmr5cqbKEqKKzqK5/cpbKOZeaVE5Wvu2nFds9PntbMuJa9rP5BneU40ch1tPtq7rJJ9b/c6p4Wdo8w1LSTOS9kdjCeeDqFSZ0M//RGCxEhcpGwmZOfGG6fc9GwXcrgWwWYshjsGpxhx2ZFcFCtnXxw3izojyd8NMvvxnX3QtZ5TchmL94ie/boEsdO5lkN+AvRyM3mLF7vu3carwy2aZ1RFy+EeoHowDPB2arQ9pv5ajdwlAfDBpo3tF/KbUO0nVRl9w8MOOkCeimjoU5B/YromYX1/iYQDTDjx9pbgOzh3vKZJxZBhq+1/lyqJls7txHJEkjf+2X64tzNIZmFGNZiLiNWVgPQbaErytlzQIvMe34tXKdlSne2wGWfN+nkfsyJ6GvfpWz9fnqbDHV/t8hlWPOiM3viuSyc2EvtU+LApUtGP8RiSIYWjnbcoAaQNAMQM051WJiRtaETsx4Upd8ruZA+eqYREdlHk+cyqgrZrL1Nc79Yvz6mVKHAmqfawB+WkHe8I0gVlLFAfEzLoBIor2NI9++JZO//ZmfzO/cyRgDMAYvOJG1IFvtAipe28SxMVDxebY3UzNaWzHQrcbhKxb98xa8dIUlxrOPvK0hi1lt1WP4y1i6ZLpl7Rz0Clvk/L6pFNphMUNVaRMrPx0c6pV5eo2Tajbu25t7mJgUOW2lmZoO7uW6+mkhhbe5NoCQUt0KeAT9stLn8J+Tv6OTr/plbADxOydgnVj5pzhpQyewqtDnyDmUsGDNKMvBUfyUhjynpNowOW95KIEfu8P6EpaUs/iHn+qyEnvwf3OuSXk=';
        $this->signatures = json_decode(gzuncompress(base64_decode($encoded)), true);
    }

    /**
     * Add custom signatures
     */
    public function add_signatures($new_signatures) {
        if (is_array($new_signatures)) {
            $this->signatures = array_merge($this->signatures, $new_signatures);
        }
    }

    /**
     * Get all signatures
     */
    public function get_signatures() {
        return $this->signatures;
    }

    /**
     * Get signature count
     */
    public function count() {
        return count($this->signatures);
    }

    /**
     * Scan content against all signatures
     */
    public function scan_content($content, $table) {
        $threats = [];

        foreach ($this->signatures as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $threats[] = [
                    'pattern' => $pattern,
                    'match' => substr($matches[0], 0, 100), // First 100 chars of match
                    'table' => $table,
                    'content_preview' => substr($content, 0, 200), // Content preview
                ];
            }
        }

        return $threats;
    }
}

/**
 * Helper function to get malware signatures object
 */
function clean_sweep_get_malware_signatures() {
    static $signatures;
    if (!isset($signatures)) {
        $signatures = new Clean_Sweep_Malware_Signatures();
    }
    return $signatures;
}
