(() => {
  'use strict';

  const roots = document.querySelectorAll('[data-pad3d-root]');
  roots.forEach(boot);

  function boot(root) {
    const canvas = root.querySelector('[data-pad3d-canvas]');
    const status = root.querySelector('[data-pad3d-status]');
    if (!canvas || !status) return;

    const read = (key) => Number(root.dataset[key]);
    const headerOd = read('headerOd');
    const branchOd = read('branchOd');
    const major = read('major');
    const minor = read('minor');
    const thickness = read('thickness');
    const beta = read('beta');
    const offset = read('offset');
    const method = root.dataset.method || '';
    let outerPerimeter = [];
    try {
      outerPerimeter = JSON.parse(root.dataset.outerPerimeter || '[]');
    } catch (_) {
      outerPerimeter = [];
    }
    const majorDirection = root.dataset.majorDirection || '';
    const minorDirection = root.dataset.minorDirection || '';
    const renderError = root.dataset.renderError || 'The 3D schematic is unavailable for this snapshot.';
    const spanError = root.dataset.spanError || renderError;
    const validPerimeterPoints = Array.isArray(outerPerimeter)
      && outerPerimeter.length >= 5
      && outerPerimeter.every((point) => Array.isArray(point) && point.length === 2 && point.every(Number.isFinite));
    const firstPerimeterPoint = validPerimeterPoints ? outerPerimeter[0] : [];
    const lastPerimeterPoint = validPerimeterPoints ? outerPerimeter[outerPerimeter.length - 1] : [];
    const openPerimeter = validPerimeterPoints ? outerPerimeter.slice(0, -1) : [];
    const perimeterIsClosed = validPerimeterPoints
      && Math.abs(firstPerimeterPoint[0] - lastPerimeterPoint[0]) <= 1e-6
      && Math.abs(firstPerimeterPoint[1] - lastPerimeterPoint[1]) <= 1e-6;
    const perimeterSpansMatch = perimeterIsClosed
      && Math.abs(2 * Math.max(...openPerimeter.map((point) => Math.abs(point[0]))) - minor) <= 0.01
      && Math.abs(2 * Math.max(...openPerimeter.map((point) => Math.abs(point[1]))) - major) <= 0.01;

    const values = [headerOd, branchOd, major, minor, thickness, beta, offset];
    if (values.some((value) => !Number.isFinite(value))
      || headerOd <= 0 || branchOd <= 0 || branchOd > headerOd || major <= minor || minor <= 0 || thickness <= 0
      || Math.abs(beta - 90) > 1e-6 || Math.abs(offset) > 1e-6
      || method !== 'CENTERED_90_DEGREE_SADDLE_PERIMETER_V2'
      || !perimeterSpansMatch
      || majorDirection !== 'AROUND_HEADER' || minorDirection !== 'ALONG_HEADER') {
      showStatus(renderError);
      return;
    }

    const radius = headerOd / 2;
    const branchRadius = branchOd / 2;
    const circumference = 2 * Math.PI * radius;
    if (major >= circumference * 0.98) {
      showStatus(spanError);
      return;
    }

    let context;
    try {
      context = canvas.getContext('2d');
    } catch (_) {
      context = null;
    }
    if (!context) {
      showStatus(renderError);
      return;
    }

    const defaultCamera = { azimuth: 0.72, elevation: 0.52, zoom: 1 };
    const camera = { ...defaultCamera };
    const triangles = buildScene(outerPerimeter);
    let initialized = false;
    let dragging = false;
    let lastPointer = null;

    function showStatus(message) {
      status.textContent = message;
      status.hidden = false;
      canvas.setAttribute('aria-label', message);
    }

    function activate() {
      if (initialized || !root.open) return;
      initialized = true;
      canvas.addEventListener('pointerdown', onPointerDown);
      canvas.addEventListener('pointermove', onPointerMove);
      canvas.addEventListener('pointerup', onPointerUp);
      canvas.addEventListener('pointercancel', onPointerUp);
      canvas.addEventListener('wheel', onWheel, { passive: false });
      canvas.addEventListener('keydown', onKeyDown);

      root.querySelectorAll('[data-pad3d-rotate]').forEach((button) => {
        button.addEventListener('click', () => {
          camera.azimuth += Number(button.dataset.pad3dRotate) || 0;
          draw();
        });
      });
      root.querySelector('[data-pad3d-reset]')?.addEventListener('click', () => {
        Object.assign(camera, defaultCamera);
        draw();
      });

      if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(draw);
        observer.observe(canvas);
      } else {
        window.addEventListener('resize', draw, { passive: true });
      }
      draw();
    }

    root.addEventListener('toggle', () => {
      if (root.open) {
        activate();
        draw();
      }
    });
    if (root.open) activate();

    function onPointerDown(event) {
      if (event.button !== undefined && event.button !== 0) return;
      dragging = true;
      lastPointer = { x: event.clientX, y: event.clientY };
      canvas.style.cursor = 'grabbing';
      canvas.setPointerCapture?.(event.pointerId);
      event.preventDefault();
    }

    function onPointerMove(event) {
      if (!dragging || !lastPointer) return;
      const dx = event.clientX - lastPointer.x;
      const dy = event.clientY - lastPointer.y;
      lastPointer = { x: event.clientX, y: event.clientY };
      camera.azimuth += dx * 0.009;
      camera.elevation = clamp(camera.elevation + dy * 0.007, 0.08, 1.35);
      draw();
    }

    function onPointerUp(event) {
      dragging = false;
      lastPointer = null;
      canvas.style.cursor = 'grab';
      if (event.pointerId !== undefined) canvas.releasePointerCapture?.(event.pointerId);
    }

    function onWheel(event) {
      event.preventDefault();
      camera.zoom = clamp(camera.zoom * Math.exp(-event.deltaY * 0.001), 0.55, 2.4);
      draw();
    }

    function onKeyDown(event) {
      const step = 0.12;
      if (event.key === 'ArrowLeft') camera.azimuth -= step;
      else if (event.key === 'ArrowRight') camera.azimuth += step;
      else if (event.key === 'ArrowUp') camera.elevation = clamp(camera.elevation - step, 0.08, 1.35);
      else if (event.key === 'ArrowDown') camera.elevation = clamp(camera.elevation + step, 0.08, 1.35);
      else if (event.key === '+' || event.key === '=') camera.zoom = clamp(camera.zoom * 1.12, 0.55, 2.4);
      else if (event.key === '-') camera.zoom = clamp(camera.zoom / 1.12, 0.55, 2.4);
      else return;
      event.preventDefault();
      draw();
    }

    function draw() {
      if (!root.open) return;
      const rect = canvas.getBoundingClientRect();
      const width = rect.width;
      const height = rect.height;
      if (width < 40 || height < 80) return;

      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const pixelWidth = Math.round(width * dpr);
      const pixelHeight = Math.round(height * dpr);
      if (canvas.width !== pixelWidth || canvas.height !== pixelHeight) {
        canvas.width = pixelWidth;
        canvas.height = pixelHeight;
      }
      context.setTransform(dpr, 0, 0, dpr, 0, 0);
      context.clearRect(0, 0, width, height);
      context.fillStyle = '#07111f';
      context.fillRect(0, 0, width, height);

      const basis = cameraBasis(camera.azimuth, camera.elevation);
      const center = [0, 0, radius * 0.82];
      const sceneLength = 4.5 * radius;
      const sceneHeight = 3.8 * radius;
      const scale = Math.min((width - 44) / (sceneLength + radius), (height - 42) / sceneHeight) * camera.zoom;
      const project = (point) => projectPoint(point, center, basis, scale, width, height);

      const projected = triangles.map((triangle) => {
        const points = triangle.points.map(project);
        const depth = points.reduce((sum, point) => sum + point.depth, 0) / points.length;
        return { points, depth, color: triangle.color };
      }).sort((a, b) => a.depth - b.depth);

      context.lineJoin = 'round';
      context.lineCap = 'round';
      projected.forEach((triangle) => {
        context.beginPath();
        context.moveTo(triangle.points[0].x, triangle.points[0].y);
        context.lineTo(triangle.points[1].x, triangle.points[1].y);
        context.lineTo(triangle.points[2].x, triangle.points[2].y);
        context.closePath();
        context.fillStyle = triangle.color;
        context.fill();
        context.strokeStyle = 'rgba(2, 6, 23, 0.23)';
        context.lineWidth = 0.45;
        context.stroke();
      });

      drawDimensions(project);
    }

    function drawDimensions(project) {
      const surfaceRadius = radius + Math.max(thickness, 0.4) + radius * 0.018;
      const halfArc = major / (2 * radius);
      const majorPoints = [];
      const majorSteps = 32;
      for (let i = 0; i <= majorSteps; i++) {
        const angle = -halfArc + (2 * halfArc * i) / majorSteps;
        majorPoints.push(project([0, surfaceRadius * Math.sin(angle), surfaceRadius * Math.cos(angle)]));
      }
      strokeDimension(majorPoints, 'A', '#fde68a');

      const halfLength = minor / 2;
      const minorPoints = [
        project([-halfLength, 0, surfaceRadius]),
        project([halfLength, 0, surfaceRadius]),
      ];
      strokeDimension(minorPoints, 'B', '#7dd3fc');

      const angle = halfArc * 0.72;
      const x = halfLength * 0.68;
      const thicknessPoints = [
        project([x, (radius + 0.35) * Math.sin(angle), (radius + 0.35) * Math.cos(angle)]),
        project([x, (radius + Math.max(thickness, 0.4)) * Math.sin(angle), (radius + Math.max(thickness, 0.4)) * Math.cos(angle)]),
      ];
      strokeDimension(thicknessPoints, 'T', '#86efac');
    }

    function strokeDimension(points, label, color) {
      if (points.length < 2) return;
      context.save();
      context.strokeStyle = color;
      context.fillStyle = color;
      context.lineWidth = 1.6;
      context.setLineDash([4, 3]);
      context.beginPath();
      context.moveTo(points[0].x, points[0].y);
      for (let i = 1; i < points.length; i++) context.lineTo(points[i].x, points[i].y);
      context.stroke();
      context.setLineDash([]);

      const first = points[0];
      const last = points[points.length - 1];
      drawArrowHead(first, points[1], color);
      drawArrowHead(last, points[points.length - 2], color);

      const middle = points[Math.floor(points.length / 2)];
      context.font = '700 12px system-ui, sans-serif';
      context.textAlign = 'center';
      context.textBaseline = 'middle';
      context.fillStyle = 'rgba(2, 6, 23, 0.88)';
      context.beginPath();
      context.arc(middle.x, middle.y, 9, 0, Math.PI * 2);
      context.fill();
      context.fillStyle = color;
      context.fillText(label, middle.x, middle.y + 0.5);
      context.restore();
    }

    function drawArrowHead(tip, toward, color) {
      const angle = Math.atan2(tip.y - toward.y, tip.x - toward.x);
      const length = 7;
      context.beginPath();
      context.moveTo(tip.x, tip.y);
      context.lineTo(tip.x - length * Math.cos(angle - 0.48), tip.y - length * Math.sin(angle - 0.48));
      context.lineTo(tip.x - length * Math.cos(angle + 0.48), tip.y - length * Math.sin(angle + 0.48));
      context.closePath();
      context.fillStyle = color;
      context.fill();
    }

    function buildScene(perimeterPoints) {
      const output = [];
      const headerLength = radius * 4.5;
      const headerXSegments = 14;
      const circularSegments = 48;
      const startX = -headerLength / 2;
      const headerBase = [71, 85, 105];
      const padBase = [217, 119, 6];
      const branchBase = [148, 163, 184];
      const light = normalize([-0.48, -0.62, 0.88]);

      const headerPoint = (x, angle) => [x, radius * Math.sin(angle), radius * Math.cos(angle)];
      for (let i = 0; i < headerXSegments; i++) {
        const x0 = startX + (headerLength * i) / headerXSegments;
        const x1 = startX + (headerLength * (i + 1)) / headerXSegments;
        for (let j = 0; j < circularSegments; j++) {
          const a0 = (Math.PI * 2 * j) / circularSegments;
          const a1 = (Math.PI * 2 * (j + 1)) / circularSegments;
          const p00 = headerPoint(x0, a0);
          const p10 = headerPoint(x1, a0);
          const p11 = headerPoint(x1, a1);
          const p01 = headerPoint(x0, a1);
          const normal = [0, Math.sin((a0 + a1) / 2), Math.cos((a0 + a1) / 2)];
          const color = shade(headerBase, normal, light);
          addTriangle(output, p00, p10, p11, color);
          addTriangle(output, p00, p11, p01, color);
        }
      }

      [-headerLength / 2, headerLength / 2].forEach((x, endIndex) => {
        const centerPoint = [x, 0, 0];
        const capColor = endIndex === 0 ? '#334155' : '#475569';
        for (let j = 0; j < circularSegments; j++) {
          const a0 = (Math.PI * 2 * j) / circularSegments;
          const a1 = (Math.PI * 2 * (j + 1)) / circularSegments;
          addTriangle(output, centerPoint, headerPoint(x, a0), headerPoint(x, a1), capColor);
        }
      });

      const topRadius = radius + Math.max(thickness, 0.4);
      const padSegments = perimeterPoints.length - 1;
      const padPoint = (point, shellRadius) => {
        const [headerAxisX, developedArc] = point;
        const around = developedArc / radius;
        return [headerAxisX, shellRadius * Math.sin(around), shellRadius * Math.cos(around)];
      };
      const padCenter = padPoint([0, 0], topRadius);

      for (let i = 0; i < padSegments; i++) {
        const next = (i + 1) % padSegments;
        const p0 = padCenter;
        const p1 = padPoint(perimeterPoints[i], topRadius);
        const p2 = padPoint(perimeterPoints[next], topRadius);
        const midArc = (perimeterPoints[i][1] + perimeterPoints[next][1]) / 2;
        const around = midArc / radius;
        const normal = [0, Math.sin(around), Math.cos(around)];
        const color = shade(padBase, normal, light);
        addTriangle(output, p0, p1, p2, color);
      }

      const edgeBottomRadius = radius + 0.18;
      for (let i = 0; i < padSegments; i++) {
        const next = (i + 1) % padSegments;
        const b0 = padPoint(perimeterPoints[i], edgeBottomRadius);
        const b1 = padPoint(perimeterPoints[next], edgeBottomRadius);
        const t0 = padPoint(perimeterPoints[i], topRadius);
        const t1 = padPoint(perimeterPoints[next], topRadius);
        addTriangle(output, b0, b1, t1, '#9a3412');
        addTriangle(output, b0, t1, t0, '#9a3412');
      }

      const branchBottom = topRadius - 0.12;
      const branchTop = radius + radius * 1.65;
      const branchSegments = 36;
      const innerRadius = branchRadius * 0.72;
      const branchPoint = (r, angle, z) => [r * Math.cos(angle), r * Math.sin(angle), z];
      for (let j = 0; j < branchSegments; j++) {
        const a0 = (Math.PI * 2 * j) / branchSegments;
        const a1 = (Math.PI * 2 * (j + 1)) / branchSegments;
        const b0 = branchPoint(branchRadius, a0, branchBottom);
        const b1 = branchPoint(branchRadius, a1, branchBottom);
        const t0 = branchPoint(branchRadius, a0, branchTop);
        const t1 = branchPoint(branchRadius, a1, branchTop);
        const normal = [Math.cos((a0 + a1) / 2), Math.sin((a0 + a1) / 2), 0];
        const color = shade(branchBase, normal, light);
        addTriangle(output, b0, b1, t1, color);
        addTriangle(output, b0, t1, t0, color);
      }

      const centerTop = [0, 0, branchTop];
      const innerTop = (angle) => branchPoint(innerRadius, angle, branchTop + 0.02);
      for (let j = 0; j < branchSegments; j++) {
        const a0 = (Math.PI * 2 * j) / branchSegments;
        const a1 = (Math.PI * 2 * (j + 1)) / branchSegments;
        const outer0 = branchPoint(branchRadius, a0, branchTop);
        const outer1 = branchPoint(branchRadius, a1, branchTop);
        const inner0 = innerTop(a0);
        const inner1 = innerTop(a1);
        addTriangle(output, outer0, outer1, inner1, '#cbd5e1');
        addTriangle(output, outer0, inner1, inner0, '#cbd5e1');
        addTriangle(output, centerTop, inner1, inner0, '#0f172a');
      }

      return output;
    }
  }

  function addTriangle(output, a, b, c, color) {
    output.push({ points: [a, b, c], color });
  }

  function cameraBasis(azimuth, elevation) {
    const camera = [
      Math.cos(elevation) * Math.cos(azimuth),
      Math.cos(elevation) * Math.sin(azimuth),
      Math.sin(elevation),
    ];
    const right = normalize(cross([0, 0, 1], camera));
    const up = normalize(cross(camera, right));
    return { camera, right, up };
  }

  function projectPoint(point, center, basis, scale, width, height) {
    const relative = [point[0] - center[0], point[1] - center[1], point[2] - center[2]];
    return {
      x: width / 2 + dot(relative, basis.right) * scale,
      y: height / 2 - dot(relative, basis.up) * scale,
      depth: dot(relative, basis.camera),
    };
  }

  function shade(base, normal, light) {
    const brightness = 0.42 + 0.65 * Math.max(0, dot(normalize(normal), light));
    const rgb = Array.isArray(base) ? base : hexToRgb(base);
    const shaded = rgb.map((channel) => Math.round(clamp(channel * brightness, 0, 255)));
    return `rgb(${shaded[0]}, ${shaded[1]}, ${shaded[2]})`;
  }

  function hexToRgb(hex) {
    const value = hex.replace('#', '');
    return [0, 2, 4].map((index) => parseInt(value.slice(index, index + 2), 16));
  }

  function dot(a, b) {
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
  }

  function cross(a, b) {
    return [
      a[1] * b[2] - a[2] * b[1],
      a[2] * b[0] - a[0] * b[2],
      a[0] * b[1] - a[1] * b[0],
    ];
  }

  function normalize(vector) {
    const length = Math.hypot(vector[0], vector[1], vector[2]) || 1;
    return [vector[0] / length, vector[1] / length, vector[2] / length];
  }

  function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, value));
  }
})();
