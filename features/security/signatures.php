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
        $encoded = 'eJytOwtX27jSf4XL7TlNAkkTUmjpi+WRPnYpcKGP3cXgq9hK4sa2vH7kUcR/vzOSbMuOHdj9vnNa7Eij0WikeWp8vWk8ozPiGkbUMowGPq5vnxqb8HJjPNvchu5oQl3XpAtqaUCG8cR45oj+IGSWyQLq13TXdzm+5SY2LXaaHwZf1gNcnF9lECH9K3FCajLfKoNdDv7zdZBDjhyXmmMamxbzY+rHUYkmY77VaU3iOMgGlAi/Brbc6ACSb41r0v552P6z297fMp69vbnb6Xa371OYqzh0/LFhdEYh844nJDxmNu20bGYlHhABHRZjU4em8IKSRt7dBIiQEnvZabnMIrHDgKTOJKSjTqsxdBCBu+QsYfDiMB6w0J8kQ26FyyBmzRSrNt08dGLgVKPTehNZoRPEgCcOyWjkWD8Sf7rkdMEs17GmzQJJHeDclYKHX8EkwOeBY79NwUgYkqUJXI5pWGatidxNARMSsTBeCyJxzYk7fQSYR4K1UOOfjj9ySayfjyGJ6N5z06YWbEcOmPgW84KQRtFDsMXGHDiKQzNkca+fAuYND85u0xoyqa8DlsT14eWtoa6Eq25c1RwP4apa+KO3ooQrCd0Vmh6mdc1WXRubT2+uQWpBdoXgGqui+/Ckjz5gmhLBiXUlAqdtDEc4tiYalFREKGHNTj2DQHKzqXkBimftTUSSYq5iSKelswGZ0NvVmFCCRVxPzAaoaI5amCsdm+kKjaBs/+uWUELd+HB6fnR4egW6i8J4cwTCiOqOkyiiYZxNcf7KMOytV8bm9S3o45axqX4bd52W5ErlbMdrhhlGtl5SaEaTsMKBXLPMA1MRmwCJnRaxPcfPMNk2rN+lqtkBfpCYhdrYCIwRC3CNnZZH3DkJqdbrEUcXAeB7AT1AOD4yxgxYFBfODuByLIclUZ3677Sk6k/7S5YFsbzNLJ5NYvKqEdNFzCex5zaFe3Cj2CJ/3PV0uSnJLix6mPEE35UE3jZv5PkW8JnB92n48cvnUwkFcnnwVi7qoCFtGreY40+cGeUeA1jG53QIbBHnjcSJB4NiK2g+BmFp2Xzu+Dabo0VVHVxjXNrWxJFrDC/xIr5YLB5leq9v3920otB6izgrjTDgDYgdcW/s2I9YU2PFrwCCQptbsxm3LD/xeECWAXGb6/aqqCwqHRhdoYHegAOw/fwefzUOXgmCyu3Nu93te1wlTshXqQRR+OJ4lCVx7udAo28fUQL+WkavacZMUlQ8NwmKggPn/qdOm6AG1NXBq7LC4sfn5799GvCrweW3wWVzjfY8QB8s1W4HUVObqXHwNltStIxi6nH0knnuMAO7oyiehIlSYiu67b/NZueuu/2y273PJ3nzLzb8Qa24bRFrQqW31cRmywU07dQBE01SJ4D1AlKrmaB8W/mqO3XzwBab3X6Hf/9KaLgsqBE7ZMIEVTLw8YiExTu5PL8AAoG+6J8MlFryHw1F7fj4gXJMyrK/O6pTim9AR9exNSZDl+amAjYRvDwATqgpdUNUY8dGSovo+3t9u32ztf2oE1971PlKfMRVMMbTmItrURX/O6f+v83HUS/sjbIvjXnQBkpGzliedo5aOiaWBc45h9+v8NDjf45/MAYVPyxsN6Qmj5q6TEnhkXLE0NKKV4/ZiUsj9UsKHMpSFaGFSDJIKiNJWIKhFmE8uIwC5YU1lZch542YNS0HpTnzwKCCKgFTfH/QQHOEZohbni13Rm5YeUszDkUupcUwyk5VRknTPhryR8T8VQe48lBX6a3HKHiJLIVhQzOKSRjXIgFXG9wwGpoxmNZMCVdvMhDogkMGkgqwkSbRIfVYLCTloVXlwCsu2hpHdZx5hYUREraj6/AksNGYrAHWF++xGfipgcuITW2M02tON1A9Ib7tpsAa1BqqYZQEN8E3iiqIWdmDyLHpkIT1+OaOjbwIo6i4EHvpE8+xcgR6J46IJ9Sj4HuzeA2LQW8FqPdM2wnB1rJwWUT0/cK8OP364dOZefLpsryIwE3GoLFhqBmQeFIcKbR5m/wgizRRUrdChDHLg22TlI+l0IsKHlS76bMgdGbNA1PpStGfuSJ1MeFDyvq/QitwEZCGFJhjYbjzjDbLFAMJkgKzeQDv16Q9Es7ey+17pWtQ68jjADRYU5uxUOofYAcQx/hwidNy2MBkrCgS1OlzRaCLUTmbdAZKtp6LVogBlM7EKCBzX7UXN05FXVYShoBThW11iCNn7JdRIw8SOGA+qIU88FuZRAvN1gmMkF8JVD4E2Gp6NCZl7NgZTVgYy3i0IBlM76nWtGm/SeI4KiqiXERhahI4sAqnjnwQj0twA8xLCk5Lmf5MxgWmELx6Ws8ji3le/f6qXnPEQm91J1yXzcsIStoRN0jxsawntK7KuZHTpeErmwGiOhObKDRCDSKbluCilaUU8ghV6juaO7E1gfDHHLpsXH+oFFcjEebrCHwaz1k4NaWzmYQraxHaG+N8m3nEKZ170xSB60oyBNpTCBnaSi6BlTLLyYxamj+DuQkd1y3ZK23ICrsiMgINT6XurmdGDlEabzsrJxJ3W2avweZPaOrcVR3cyPTY0HFp9Zk4PLq6OPzysV5wjs/PvgzOvkjD8qDNKXFlGf3lOjKsKPZcnJyv7I+MPvSh6cgq3ODSUn9WbzNFpz7giTk4+1aNC0yJyufVJTAXcUjyvav19jp58rfMaA3mAX8qM/YBuIfFqUS2gWYQNUiUbBRhVpSvNNx12iQI3PR+JKoz+tWo0YsE5y0FqTp0JasNMUTdUiohC5oGE7dp/xrGRsmwFlTPL3VaeLWxJrsv3Jc0PwGP1/JdhkLogshR70HiItAVuSS20UJLJyuzFxjlmfPAp4sExBQsmAbudsdRERwaVYSut4bEdlix6fvFt+OT4qqgt6hu2jPLXkGfJWgsvFRCg+ayULzVDFB6R8SG0lgoYOHWCrY8BAxhI12sQyxQPQ5UQ4uuHXskLJk6kUfjwqs+8FZnTQN4w39E3PHImEK4i9u+JeFVWJKDp3fCEUb1/5IzqtBaxjTiNQnGsInpL/QkPaJ+pFKsfioixLvPWKBePSey1KsM3sRrSGdOJDPGEm9IqZ9Nif5GW+QW9JZ02pD4kSsSm23gUQy+i+qRiQlYoFx/G2xWOp8LblRbpooUWeCJtIdsQdU5bl7fGs9uVELwycqZAw6l7OLK/nO07bFiNTIdeI9YAPpmq4Lzcqsypkukn076/MoBPtILh3LlA0b8wnL/dAIO8E28FG/f5KiyxEnk6RIM3oitJfYEKzBTHrRVpKCBllrcZDQx2TTTRkvPdXz9vvgRuTBp1CpTXx38J5NLNFYZGpWqaVbPiQHYwSttAIY4c7uQ5YmoOxIQ/syB8CSfp5DwwfgJmiEu4n7iuoU01jBo56kqmcKCA0PE2wwiYlaVKe60SuzJF2CNxrpCXXpw3J3ES9veGMYBiq0Y90tWfWFcI+6bCihnJNhfgGvK4XdIBkZ5hRu3Xw4MQ7dJgpPAobxf1VkcVBR1FDu1ypFih14x8suBzErWYpQ0VuJMY+XKTr1OpooerUOnJ8BbtU4rZWHuP12fdz+5vZu73e2d7n3xTm715lrcwElDuoqh193eLaNYY2htOnJ83Adj8/ffBn8YmyK/aGweTf+cGptaAkoH/HZ4+nWQgb7Y6fX7Vpe82Nvd2RvCs9fd77/c3X/R3x3tjbov4Tfds3ZJF58vd+0XvZ39PQL9wx0CcC9fkN2XeyPo73fJ3svu/k5/d1gzdZruR1LFC7ZsZ805q27unt+LpidrkIhlVKEBBKQ9koze2d17ENPhxacKNN1FhujFdq/7IJarj38bydHh1QDjB7m3nWyUUBgSm4I8/XT225X5/tOpXHBHwqGaiEy87QNveUyz+O7o9PyDeXb4uQCMsWjbp/OogLhiHZ++DD4fXlQxVhiKVhsDVo8EVQyxdUzp8jJUmXrh4l/Uogsnu9meDu2MV7v31+lB6G737zutLEBB1bt4/kI+LPkYycfOqs+K4tLKUO1uvwTJUs0F5arll7c08B5KImrIn72evUdebKv8a/K82xc5dHj/a0h2rT7NJBaO/uZr7LnVpDu91XtbPqSH7fc3d/3te7VRYiF6106mexUHQE0/zSjsw4Keom5PUR9ilZUguSkUxthlQ8GLLVQx+rDXOXUH7zSbkO0n2MdizlHlJPV0JF5w8KymsLlq0GuvpPBd3etmDgUEj+XSxevb13gCdooXJemVvrD02bV+xfVJNi3QUayRu7u+vb8pxUqlI7GmBjErL4wOsnsg3duPXvV3sHwlzbb2YRs3RTFCIcsr6Fx1aBCxdq+dInVe7cuL+v3t+9c1yMRQ5QByVW+ZIdCTrFnaQa9vmkGQPkIR7oRJ5vh9Pft8ePXb4MRsXA7OTgaXg0v+DV7OL5vm98HRh9MKEWqZoPbStT+XIiQOxx3+gXXKGxYdau8ezu916nvSvMRK1J6QwBHx2IRamRO5WqyRbU9hepT5dOc3iTtmopwITii6a9zYDBfGJjwsXz3aE0pmy/SHi5jVe+BYLIU1MGm52BE/xxOIOkLw50JjM2P27fXzgj3bB4PWa+8ftj/+2j67wPap1/4JNuo+9bjhfDOMM6IkgJMeL7xQ1Fx4XLXLGhp4YRYlGMkslj+5T3zWVgAsHHMsr8lHllE1sWIKxF8Wegidk2t3zGdmmvpVrqgeu45SYBBtdVpX0lB0pEPZsCEAQY3fzEUtEkMmzKOY5tRFDF0IlKrODb7l9hFoMVE336eNqXjIUy+qizvykBEhtfJeR1CVUZQOOk6iGIynajUvQoZ+ecjLDZ2Wndf94qvReBpDHPVUWIEHsKxQU2d1ZGWyMDw796WL97x7a3u1rVUyLrf4t5XbEt2QjCDqJYUSRiKthpQ3GIATYDwo9olExenAc8AymD6o5FKFjO7NImAlfRV06y5DarK1E/dyWzfaRbekBlt6rmYEy5u3fDuaZwTEYQKulQGrurppfQTtd+w6qEbSMX8D+N9aSuVZQ16iReIWLQnUI+Je0la5Fp4Z4L3t+yZ68VpG58m/M7O74i+gtXkjCtDecduJIPRaZjLqg1bgbwguEwvj3laZJlG3hDa0YZHI8RknP/HvD6AwYDGPXBZHB3xMvKHLZw4Zh4RbmK2NQPVM4RyHLHFpjJeUNO7v7fLeAl74LHGnxOejkPhTAFlwl45/Mo7X+jbhdugE/AeATRkmQviM2VPCgwkJCZuITE4I9mrImMOnwD9uE9te8hmZERhMgIqYhbwHQTb3qAvTAb+iGncqr0S5E8FT9SqLC6taieKDXLPghmAcVmmIOV5nJMwDsyEvb1TiqFkuGyiIlKz22K3dgCqey92oJ7PAj2LJU0OkSXmBQK4i9maRpv7/kaacivrL8CCk2SWuuFvLbtZWr3PKYwm4mgpYXZzL7wimdAmuohPhtSheQOTiq+vUvJYuK2G9vr3D5x3ehYMHCM6h30z35uAV7Cum8UyRxuPa5M3yNGMWs1W1vSe09mvA1GmthWiizw+8y0Ve19TNu95uXqP7hLggNkNZw5Kf+MOj45PB+w8fP/362+nns/OL/1xeffn67fvvf/xJhhYEY+OJ82Pqej4L/gqjOJnNwV3o9nb6z3f3Xrzcb5vpkVaTDB0/w//0ad4e0qiqHTOzPsHMC4QFZnpvg1UW0tvLa8SiychUWYxmwbcuVmWlhi72glz7PjBJbYBVlXcx2+qbgeLCa86LogYLqnVjA+cujWOLbocKsYB6EXclfiHBKVfV1GKuJ/HEidrv8HCYaGqvhWu/JWK5ztruHIWlGdU8nsKQqSV7daQIdK2ZTDO1ljeac/WWZ71VIyoGlPrXz5iOf2gJndZ8slLzpPSKDLp9fXLVI7aur733srhGugEZHQ+cFGHvYYQPgZsqlpeCWqYKvH4X7xQaOmahYd8U1p0V9IqHDD1xtWVqWGgLVQWgGaZ2ZUfZ1S75W9nmyrnq16/MlDQHPVwrnNJ/MqZKnExT+L1hYhW/ICvzoTzB6vHP1PfrVb7+Pw5Zp5704s3XYhME4sqvHku7niZUfHBrxuK7EghZ6RA8JAi48y8ZLLA3FxOIEiBwyxrNoNySNpim74wnsUdCCu8RhZPoJJ6Z+POQBAHNP0FoHOPXCe+dBRcvG/h2FLI5eAcbX4VzsHEpMwU2v6JgqZ14uXGMofbGewKcsPk3zAssN5Ys2YDZNiaJB27IHywJN4YKjxNtsCTeYKMN4WzAFBuiX82T01L6qmQrOw2CW5UfMHD9Swej8OFDES9/rw6fnoiqy8HvZjn40i4FbE5DmUcK5tEEcW012tS3eFt+22cfMw8YYPP2YAHswgkvGHB2uXEkKuVAQ30a/M5P2NzHgECuiZ+JW9XvdJhFEHK6Bz9saVY5uq2G+ORkBFtpswSclbbN/ARc+ZgSFw6VyOQs28My+7XrQV51zcwrL4mb6s7B6DR+iIJuQZOIxA7w7Z2m8mSUpr5ZEn3ZShNgG/ihtis/AZKUpuvAj2cbMQs4ZjWYL2EgfubFD1zRrNJwyMTVIyxkWKV4JJBZEMNMuE3VGznjiKc/fLqIVY1f1jZiLC52aReceWWxrMtQGSqRkMdPhmVVlkjKx8ugppJQ9011slLobm9vf6/f7z1/sZf5XZgIEpUF4n5XfYiU1k5lGQqAshm4jY4fJOh6dDwsm3BszfQqW4s2W3jpJIx9ZJqUCc1IX/d6e0Lx9XrPxXNf5sF73Z3Sc1d27xege92eenYLYOX+bNieNnsATBnihXqDhBB4cG/ZLKxD33UsFaPlT6Imjp2GG/KeBP17rop0tFo/4a6lLl1gYr3TVHy5dHw5OPwy2PhyeHQ64PoPiN5zuCzxS337K0jcCcEqxLRwMeKiNNFlouxQvFNZMqeieRO83OyMXHw8+2F57tw+eT62d9zkzw/7/VNvMftj532XfN9PTv1fXevDYvLHztfsg+4s2MgUHRz816pWqMiRuq9dC6kUTWyMmor5Knh1AdJAZ654i9G8079Urfre+O/O/w/W8Q+XWHAYVZJKj9fydf1SqpYq+wI1jTD65n+ZcRqi';
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
