<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Tournament Bracket (zig-zag connectors)</title>
<style>
  :root{
    --bg:#fafafa;
    --panel:#e6e6e6;
    --panel-dark:#cfcfcf;
    --accent:#35b86b;
    --gap:48px;
    --match-w:220px;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family: "Helvetica Neue", Arial, sans-serif;
    background:var(--bg);
    display:flex;
    justify-content:center;
    padding:36px;
  }

  .bracket {
    display:flex;
    gap:var(--gap);
    align-items:center;
    position:relative;
  }

  /* setiap kolom round */
  .round {
    display:flex;
    flex-direction:column;
    gap:28px;
    align-items:flex-start;
  }

  /* kotak match (dua tim) */
  .match {
    width:var(--match-w);
    background:linear-gradient(180deg,var(--panel),var(--panel-dark));
    border-radius:6px;
    padding:6px;
    position:relative;
    box-shadow: 0 2px 0 rgba(0,0,0,0.05);
  }
  .team{
    padding:12px 14px;
    background:#f3f3f3;
    margin:4px 0;
    border-radius:4px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    font-weight:600;
    color:#222;
  }
  .team.winner{
    background:#f6fff6;
    border-left:6px solid var(--accent);
  }

  /* Wrapper tiap match untuk memudahkan meletakkan connector */
  .match-wrap{
    position:relative;
    padding-left:28px; /* ruang untuk garis kiri */
    padding-right:28px; /* ruang untuk garis kanan */
  }

  /* Zig-zag connector ke kanan (dari match ini ke match di next round) */
  .connector-right{
    position:absolute;
    left:100%;
    top:50%;
    width: calc(var(--gap) + 20px);
    height:1px;
    transform:translateX(6px);
    pointer-events:none;
  }
  /* buat zig-zag: 3 segment menggunakan linear-gradient background trick */
  .connector-right::before{
    content:"";
    position:absolute;
    left:0; top:50%; transform:translateY(-50%);
    height:6px; width:100%;
    background:
      linear-gradient(90deg, transparent 0 calc(25% - 1px), #111 calc(25% - 1px) calc(25% + 1px), transparent calc(25% + 1px) 100%),
      linear-gradient(90deg, transparent 0 calc(50% - 1px), #111 calc(50% - 1px) calc(50% + 1px), transparent calc(50% + 1px) 100%),
      linear-gradient(90deg, transparent 0 calc(75% - 1px), #111 calc(75% - 1px) calc(75% + 1px), transparent calc(75% + 1px) 100%);
    background-repeat:no-repeat;
    background-size:25% 2px,25% 2px,25% 2px;
  }

  /* Zig-zag connector ke kiri (dari right round elements) */
  .connector-left{
    position:absolute;
    right:100%;
    top:50%;
    width: calc(var(--gap) + 20px);
    height:1px;
    transform:translateX(-6px);
    pointer-events:none;
  }
  .connector-left::before{
    content:"";
    position:absolute;
    right:0; top:50%; transform:translateY(-50%);
    height:6px; width:100%;
    background:
      linear-gradient(90deg, transparent 0 calc(25% - 1px), #111 calc(25% - 1px) calc(25% + 1px), transparent calc(25% + 1px) 100%),
      linear-gradient(90deg, transparent 0 calc(50% - 1px), #111 calc(50% - 1px) calc(50% + 1px), transparent calc(50% + 1px) 100%),
      linear-gradient(90deg, transparent 0 calc(75% - 1px), #111 calc(75% - 1px) calc(75% + 1px), transparent calc(75% + 1px) 100%);
    background-repeat:no-repeat;
    background-size:25% 2px,25% 2px,25% 2px;
  }

  /* vertical stroke that connects two matches (to next round center) */
  .v-join{
    position:absolute;
    left:100%;
    width:6px;
    height:calc(var(--match-w) * 0.2); /* just a default; we will override per item inline */
    background:#111;
    top:50%;
    transform:translateY(-50%) translateX(8px);
    border-radius:2px;
  }

  /* small black block like the image (vertical short rectangle near receiving match) */
  .small-block{
    position:absolute;
    left:-14px;
    top:50%;
    transform:translateY(-50%);
    width:8px;
    height:28px;
    background:#111;
    border-radius:2px;
  }

  /* final area */
  .final-area{
    display:flex;
    flex-direction:column;
    gap:12px;
    align-items:flex-start;
    padding-left:12px;
  }
  .champ{
    display:flex;
    align-items:center;
    gap:12px;
  }
  .cup{
    font-size:44px;
  }

  /* responsive smaller screens */
  @media (max-width:900px){
    :root{ --match-w:180px; --gap:28px;}
    .bracket{gap:18px}
  }
</style>
</head>
<body>

<div class="bracket">

  <!-- ROUND 1 -->
  <div class="round">
    <div class="match-wrap" style="margin-top:6px;">
      <div class="match">
        <div class="team">INGOUDE FC</div>
        <div class="team winner">AROWWAI FC</div>
      </div>
      <!-- connector from this match to next round -->
      <div class="connector-right"></div>
    </div>

    <div class="match-wrap">
      <div class="match">
        <div class="team">TIMMERMAN FC</div>
        <div class="team winner">RIMBERIO FC</div>
      </div>
      <div class="connector-right"></div>
    </div>

    <div class="match-wrap">
      <div class="match">
        <div class="team">BORCELLE FC</div>
        <div class="team winner">BORCELLE FC</div>
      </div>
      <div class="connector-right"></div>
    </div>

    <div class="match-wrap">
      <div class="match">
        <div class="team">WARDIERE FC</div>
        <div class="team winner">LARANA FC</div>
      </div>
      <div class="connector-right"></div>
    </div>
  </div>

  <!-- ROUND 2 -->
  <div class="round">
    <!-- top merged -->
    <div class="match-wrap" style="margin-top:28px;">
      <div class="match">
        <div class="team">AROWWAI FC</div>
        <div class="team winner">RIMBERIO FC</div>
      </div>

      <!-- vertical join (simulate the vertical short line that connects two source matches) -->
      <div class="v-join" style="height:76px; left:100%; transform:translateY(-50%) translateX(8px);"></div>
      <div class="connector-right"></div>
    </div>

    <!-- bottom merged -->
    <div class="match-wrap" style="margin-bottom:28px;">
      <div class="match">
        <div class="team">BORCELLE FC</div>
        <div class="team winner">LARANA FC</div>
      </div>
      <div class="v-join" style="height:76px; left:100%; transform:translateY(-50%) translateX(8px);"></div>
      <div class="connector-right"></div>
    </div>
  </div>

  <!-- FINAL (semi-final -> final) -->
  <div class="round">
    <div class="match-wrap" style="margin-top:46px;">
      <div class="match" style="width:240px;">
        <div class="team">RIMBERIO FC</div>
        <div class="team winner">RIMBERIO FC</div>
      </div>
      <!-- small block near receiving match -->
      <div class="small-block"></div>
    </div>

    <div style="height:80px"></div> <!-- spacing -->

    <div class="final-area">
      <div class="match" style="width:220px;">
        <div class="team">LARANA FC</div>
        <div class="team winner">LARANA FC</div>
      </div>
    </div>
  </div>

  <!-- CHAMPION -->
  <div class="round">
    <div style="width:220px; height:120px; display:flex; align-items:center; justify-content:flex-start; padding-left:8px;">
      <div class="champ">
        <div class="cup">🏆</div>
        <div style="background:#e9e9e9;padding:10px 18px;border-radius:6px;font-weight:700;">RIMBERIO FC</div>
      </div>
    </div>
  </div>

</div>

</body>
</html>
