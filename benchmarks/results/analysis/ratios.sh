#!/usr/bin/env bash
# ratios.sh statsfile -> per-workload table
awk '
function f(s,k,  a,n,i,p){n=split(s,a," ");for(i=1;i<=n;i++){split(a[i],p,"="); if(p[1]==k) return p[2]} return ""}
$1=="T"{ w=$2; im=$3; mo=$4; med=f($0,"med_ns"); p95=f($0,"p95_ns"); mn=f($0,"min_ns"); mx=f($0,"max_ns"); rec=f($0,"recs");
  noisy=(mx/med>1.25 || (p95-mn)/med>0.25)?"N":"-";
  W[w]=1; recs[w]=rec; key=w SUBSEP im SUBSEP mo; M[key]=med; P[key]=p95; NZ[key]=noisy; MX[key]=mx; MN[key]=mn
  if(!((w SUBSEP im) in B) || med<B[w SUBSEP im]){B[w SUBSEP im]=med; BM[w SUBSEP im]=mo; BN[w SUBSEP im]=noisy; BP[w SUBSEP im]=p95}
}
$1=="R"{ r=f($0,"rss_med_kb"); RSSK[$2 SUBSEP $3 SUBSEP $4]=r }
END{
 for(w in W){ if(w ~ /oversized/) continue;
  rec=recs[w]; jf=B[w SUBSEP "jsonfuse"]; 
  best=""; bt=1e30;
  n=split("php simdjson fastjson",I," ");
  for(i=1;i<=n;i++){ t=B[w SUBSEP I[i]]; if(t>0 && t<bt){bt=t;best=I[i]} }
  printf "%s jf_rps=%.0f jf_p95_ms=%.1f jf_med_ms=%.1f jf_%s best=%s/%s best_rps=%.0f best_med_ms=%.1f best_%s ratio=%.2f", w, rec/(jf/1e9), BP[w SUBSEP "jsonfuse"]/1e6, jf/1e6, BN[w SUBSEP "jsonfuse"], best, BM[w SUBSEP best], rec/(bt/1e9), bt/1e6, BN[w SUBSEP best], bt/jf;
  for(i=1;i<=n;i++){ t=B[w SUBSEP I[i]]; printf " vs_%s=%.2f(%s,%s)", I[i], t/jf, BM[w SUBSEP I[i]], BN[w SUBSEP I[i]] }
  printf " rss_jf_kb=%s rss_best_kb=%s\n", RSSK[w SUBSEP "jsonfuse" SUBSEP "jit"], RSSK[w SUBSEP best SUBSEP BM[w SUBSEP best]]
 }}' "$1" | sort -t- -k1,1 -k3,3n -k2,2
