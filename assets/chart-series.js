'use strict';
/* Segment continuity depends on actual polling cadence, not only SQL group buckets. */
(function(root){
  function limitMs(bucketSeconds,pollSeconds){
    const b=Number(bucketSeconds),p=Number(pollSeconds);
    const cadence=Math.max(1,Number.isFinite(b)?b:1,Number.isFinite(p)?p:1);
    return cadence*2500;
  }
  function joins(previous,current,bucketSeconds,pollSeconds){
    const a=Number(previous),b=Number(current);
    return Number.isFinite(a)&&Number.isFinite(b)&&b>a&&b-a<=limitMs(bucketSeconds,pollSeconds);
  }
  const api={limitMs,joins};
  root.USCAChartSeries=api;
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window!=='undefined'?window:globalThis);
