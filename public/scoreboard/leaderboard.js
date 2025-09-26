// ===== Config =====
const PAR_TOTAL = (window.SCOREBOARD_CONFIG && window.SCOREBOARD_CONFIG.parTotal) || 72;
const INITIAL_UPDATED_AT = window.SCOREBOARD_CONFIG?.updatedAt ?? Date.now(); // 표시할 기준 시각(ms)
const STATUS = window.SCOREBOARD_CONFIG?.status ?? 'live'; // 'live' | 'final'

// ===== Sample data (임시) =====
const players = [
  { name: "Minami Katsu",     strokes: 64 },
  { name: "Sarah Schmelzel",  strokes: 64 },
  { name: "Alison Lee",       strokes: 65 },
  { name: "Leona Maguire",    strokes: 65 },
  { name: "Lilia Vu",         strokes: 65 },
  { name: "Nasa Hataoka",     strokes: 65 },
  { name: "Somi Lee",         strokes: 65 },
  { name: "Brooke Henderson", strokes: 66 },
  { name: "Jin Young Ko",     strokes: 67 },
  { name: "Danielle Kang",    strokes: 68 },
];

// 플레이어별 18홀(임시). id는 아래 build에서 1..N으로 부여됨.
const playerHolesById = {
  1: [3,4,4,3,4,4,3,4,3, 4,3,4,3,4,4,3,3,4], // 64
  2: [3,4,4,3,4,4,3,4,3, 4,3,4,3,4,4,3,3,4], // 64
  3: [4,4,3,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 65
  4: [4,4,3,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 65
  5: [4,4,3,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 65
  6: [4,4,3,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 65
  7: [4,4,3,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 65
  8: [4,4,4,4,4,4,3,4,3, 4,3,4,4,3,4,3,3,4], // 66
  9: [4,4,4,4,4,4,4,4,3, 4,3,4,4,3,4,3,3,4], // 67
 10: [4,4,4,4,5,4,4,4,3, 4,3,4,4,3,4,3,3,4], // 68
};

const PAR_BY_HOLE = window.SCOREBOARD_CONFIG?.parByHole ?? [
  4,4,3,4,4,4,3,4,4,   // 1~9
  4,3,4,5,4,4,3,3,4    // 10~18
];

// ===== Helpers =====
const toPar   = (strokes) => strokes - PAR_TOTAL; // 음수면 언더파
const fmtToPar = (v) => (v === 0 ? "E" : (v > 0 ? `+${v}` : `${v}`));
const classToPar = (v) => (v === 0 ? "even" : (v < 0 ? "neg" : "pos"));
const keyOf   = (p) => `${p.toPar}|${p.strokes}`;

function formatUpdatedAt(ms) {
  const d = new Date(ms);
  const pad = (n) => String(n).padStart(2, "0");
  const y = d.getFullYear();
  const m = pad(d.getMonth() + 1);
  const dd = pad(d.getDate());
  const hh = pad(d.getHours());
  const mi = pad(d.getMinutes());
  const tz = Intl.DateTimeFormat(undefined, { timeZoneName: 'short' })
              .formatToParts(d).find(p => p.type === 'timeZoneName')?.value ?? '';
  return `${y}-${m}-${dd} ${hh}:${mi} ${tz}`;
}
function renderUpdatedAt(ms) {
  const el = document.getElementById('updatedAt');
  if (el) el.textContent = `Last updated: ${formatUpdatedAt(ms)}`;
}
function renderStatus(){
  const el = document.getElementById('statusBadge');
  if (!el) return;
  if (STATUS === 'final'){
    el.textContent = 'Final';
    el.classList.remove('live'); el.classList.add('final');
  } else {
    el.textContent = 'Live';
    el.classList.remove('final'); el.classList.add('live');
  }
}

function sumRange(arr, s, e){
  let t = 0;
  for (let i=s;i<=e;i++) if (Number.isFinite(arr[i])) t += arr[i];
  return t;
}
function td(v){ return Number.isFinite(v) ? v : '—'; }

// ===== Pipeline: enrich → sort → posText → render =====
let currentData = [];           // 정렬/가공된 데이터 보관
let openDetailEl = null;        // 열려 있는 디테일 DOM
let openRowEl = null;           // 디테일을 연 행

function buildLeaderboardRows(list) {
  const enriched = list
    .filter(p => Number.isFinite(p.strokes))
    .map((p, i) => ({ ...p, id: p.id ?? (i + 1), toPar: toPar(p.strokes) }));

  enriched.sort((a, b) => {
    if (a.toPar !== b.toPar) return a.toPar - b.toPar;
    if (a.strokes !== b.strokes) return a.strokes - b.strokes;
    return a.name.localeCompare(b.name);
  });

  // 동타 그룹 → POS 텍스트만
  const groupCount = new Map();
  enriched.forEach(p => {
    const k = keyOf(p);
    groupCount.set(k, (groupCount.get(k) || 0) + 1);
  });

  let position = 1, lastKey = null;
  enriched.forEach((p, i) => {
    const k = keyOf(p);
    if (k !== lastKey) { position = i + 1; lastKey = k; }
    const tied = (groupCount.get(k) || 0) > 1;
    p.posText = tied ? `T${position}` : `${position}`;
  });

  return enriched;
}

function renderLeaderboard(enriched) {
  const board = document.getElementById('board');
  if (!board) return;

  // 이전 렌더 제거
  board.querySelectorAll('.row, .detail').forEach(el => el.remove());
  openDetailEl = null; openRowEl = null;

  // 행 렌더
  enriched.forEach(p => {
    const row = document.createElement('div');
    row.className = 'row';
    row.dataset.id = String(p.id);
    row.innerHTML = `
      <div class="pos">${p.posText}</div>
      <div class="name" aria-label="${p.name}">${p.name}</div>
      <div class="num toPar ${classToPar(p.toPar)}">${fmtToPar(p.toPar)}</div>
      <div class="num">${p.strokes}</div>
    `;
    board.appendChild(row);
  });
}

// 디테일 DOM 생성
function buildDetailEl(player){
  const holes = playerHolesById[player.id];
  const wrap = document.createElement('div');
  wrap.className = 'detail';

  if (!holes || holes.length !== 18){
    wrap.innerHTML = `
      <div class="detail-card">
        <div class="detail-empty">No per-hole scores yet.</div>
      </div>
    `;
    return wrap;
  }

  const out = sumRange(holes, 0, 8);
  const inn = sumRange(holes, 9, 17);
  const total = out + inn;

  const parOut   = sumRange(PAR_BY_HOLE, 0, 8);
  const parIn    = sumRange(PAR_BY_HOLE, 9, 17);
  const parTotal = parOut + parIn;

  const headerL = Array.from({length:9}, (_,i)=> i+1).map(n=>`<th>${n}</th>`).join('');
  const headerR = Array.from({length:9}, (_,i)=> i+10).map(n=>`<th>${n}</th>`).join('');

  const parRowL = PAR_BY_HOLE.slice(0,9).map(v=>`<td class="par">${v}</td>`).join('');
  const parRowR = PAR_BY_HOLE.slice(9).map(v=>`<td class="par">${v}</td>`).join('');

  const holeCell = (stroke, par) => {
    if (!Number.isFinite(stroke)) return `<td class="score-cell">—</td>`;
    const diff = stroke - par;
    let cls = 'score-cell';
    if (diff === 0) {
      // 파: 숫자만 (장식 없음)
      return `<td class="${cls} even">${stroke}</td>`;
    } else if (diff === -1) {
      cls += ' neg score-birdie';
    } else if (diff === -2) {
      cls += ' neg score-eagle';
    } else if (diff <= -3) {
      cls += ' neg score-albatross';
    } else if (diff === +1) {
      cls += ' pos score-bogey';
    } else if (diff >= +2) {
      cls += ' pos score-dblbogey';
    }
    return `<td class="${cls}">${stroke}</td>`;
  };

  const rowL = holes.slice(0,9).map((v,i)=> holeCell(v, PAR_BY_HOLE[i])).join('');
  const rowR = holes.slice(9).map((v,i)=> holeCell(v, PAR_BY_HOLE[i+9])).join('');

  wrap.innerHTML = `
    <div class="detail-card">
      <table class="detail-table">
        <!-- ✅ 맨 앞에 라벨 컬럼 추가 -->
        <colgroup>
          <col class="label">
          ${'<col class="hole">'.repeat(9)}
          <col class="sum">
          ${'<col class="hole">'.repeat(9)}
          <col class="sum"><col class="total-col">
        </colgroup>
        <thead>
          <tr class="subhdr">
            <th class="rowlabel">HOLE</th>
            ${headerL}<th>OUT</th>${headerR}<th>IN</th><th>TOTAL</th>
          </tr>
          <tr class="par-row">
            <td class="rowlabel">PAR</td>
            ${parRowL}<td class="total">${parOut}</td>${parRowR}<td class="total">${parIn}</td><td class="total">${parTotal}</td>
          </tr>
        </thead>
        <tbody>
          <tr class="score-row">
            <td class="rowlabel strong">SCORE</td>
            ${rowL}<td class="total">${out}</td>${rowR}<td class="total">${inn}</td><td class="total">${total}</td>
          </tr>
        </tbody>
      </table>
    </div>
  `;
  return wrap;
}



// 토글 동작 (클릭 전용, 키보드 포커스 핸들링 없음)
function toggleDetail(rowEl){
  const id = rowEl.dataset.id;
  const player = currentData.find(p => String(p.id) === id);
  if (!player) return;

  // 같은 행이면 닫기
  if (openRowEl === rowEl){
    openDetailEl?.remove();
    openDetailEl = null; openRowEl = null;
    return;
  }
  // 다른 행 열기 전 기존 닫기
  if (openDetailEl){
    openDetailEl.remove();
    openDetailEl = null; openRowEl = null;
  }
  // 새로 열기
  const detail = buildDetailEl(player);
  rowEl.insertAdjacentElement('afterend', detail);
  openDetailEl = detail; openRowEl = rowEl;
}

// ===== Boot =====
(function init() {
  renderUpdatedAt(INITIAL_UPDATED_AT);
  renderStatus();
  currentData = buildLeaderboardRows(players);
  renderLeaderboard(currentData);

  // 이름 클릭 → 디테일 토글 (이벤트 위임)
  const board = document.getElementById('board');
  board.addEventListener('click', (e)=>{
    const nameEl = e.target.closest('.name');
    if (!nameEl) return;
    const rowEl = nameEl.closest('.row');
    if (!rowEl) return;
    toggleDetail(rowEl);
  });

  // (선택) 주기적 타임스탬프 갱신이 필요하면 사용
  // setInterval(() => renderUpdatedAt(Date.now()), 60_000);
})();
