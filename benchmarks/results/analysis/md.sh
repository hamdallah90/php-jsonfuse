#!/usr/bin/env bash
awk '
function f(s,k,  a,n,i,p){n=split(s,a," ");for(i=1;i<=n;i++){split(a[i],p,"="); if(p[1]==k) return p[2]} return ""}
$1=="T"{ w=$2; im=$3; mo=$4; med=f($0,"med_ns"); p95=f($0,"p95_ns"); mn=f($0,"min_ns"); mx=f($0,"max_ns"); rec=f($0,"recs");
  W[w]=1; recs[w]=rec; nz=(mx/med>1.25 || (p95-mn)/med>0.25)?1:0
  if(!((w SUBSEP im) in B) || med<B[w SUBSEP im]){B[w SUBSEP im]=med; BM[w SUBSEP im]=mo; BN[w SUBSEP im]=nz; BP[w SUBSEP im]=p95}}
$1=="R"{ RK[$2 SUBSEP $3 SUBSEP $4]=f($0,"rss_med_kb") }
END{ for(w in W){ if(w~/oversized/)continue; rec=recs[w]; jf=B[w SUBSEP "jsonfuse"]; bt=1e30; best="";
  n=split("php simdjson fastjson",I," "); for(i=1;i<=n;i++){t=B[w SUBSEP I[i]]; if(t>0&&t<bt){bt=t;best=I[i]}}
  printf "| %s | %.0f (%.1f/%.1f ms)%s | %s %s | %.0f | %.2f | %.2f | %.2f | %.2f | %.1f / %.1f |\n", w, rec/(jf/1e9), jf/1e6, BP[w SUBSEP "jsonfuse"]/1e6, (BN[w SUBSEP "jsonfuse"]?"*":""), best, BM[w SUBSEP best], rec/(bt/1e9), bt/jf, B[w SUBSEP "php"]/jf, B[w SUBSEP "fastjson"]/jf, B[w SUBSEP "simdjson"]/jf, RK[w SUBSEP "jsonfuse" SUBSEP "jit"]/1024, RK[w SUBSEP best SUBSEP BM[w SUBSEP best]]/1024 }}' "$1" | sort -t'|' -k2,2
