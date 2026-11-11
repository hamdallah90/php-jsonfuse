#!/usr/bin/env bash
# stats.sh DIR... : per config table: workload impl mode kind n median_ns p95_ns min_ns max_ns records maxrss_med_kb
for d in "$@"; do cat "$d"/raw.log "$d"/*/raw.log 2>/dev/null; done | grep '^RAW kind=' | awk '
{ k=""; for(i=1;i<=NF;i++){split($i,a,"="); v[a[1]]=a[2]}
  print v["kind"], v["workload"], v["impl"], v["mode"], v["ns"], v["maxrss_kb"], v["read"] }' |
sort -k1,1 -k2,2 -k3,3 -k4,4 -k5,5n | awk '
function flush(){ if(n==0) return;
  if(kind=="timed"){ med=(n%2)?x[(n+1)/2]:(x[n/2]+x[n/2+1])/2; p=int(0.95*n); if(p<0.95*n)p++; if(p<1)p=1;
    printf "%s %s %s %s n=%d med_ns=%d p95_ns=%d min_ns=%d max_ns=%d recs=%d rps=%.0f\n","T",w,im,mo,n,med,x[p],x[1],x[n],recs,recs/(med/1e9) }
  else { # rss sorted by ns not rss; sort rss
    m=0; for(i=1;i<=n;i++) r[i]=rs[i];
    for(i=1;i<=n;i++)for(j=i+1;j<=n;j++) if(r[j]<r[i]){t=r[i];r[i]=r[j];r[j]=t}
    med=(n%2)?r[(n+1)/2]:(r[n/2]+r[n/2+1])/2; printf "%s %s %s %s n=%d rss_med_kb=%d rss_max_kb=%d\n","R",w,im,mo,n,med,r[n] }
  n=0 }
{ key=$1" "$2" "$3" "$4; if(key!=pk){flush(); pk=key; kind=$1;w=$2;im=$3;mo=$4} n++; x[n]=$5; rs[n]=$6; recs=$7 }
END{flush()}'
