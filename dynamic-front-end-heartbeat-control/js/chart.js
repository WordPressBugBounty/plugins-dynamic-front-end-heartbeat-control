(function () {
    function DfehcChart(canvas, config) {
        if (!(canvas instanceof HTMLCanvasElement)) return;
        const ctx = canvas.getContext("2d");

        let hoverIndex = -1;

        function render() {
            const dpr = window.devicePixelRatio || 1;
            const bounds = canvas.getBoundingClientRect();
            if (bounds.width === 0 || bounds.height === 0) return;

            canvas.width = bounds.width * dpr;
            canvas.height = bounds.height * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            const width = bounds.width;
            const height = bounds.height;

            const labels = config.data.labels;
            const dataset = config.data.datasets[0];
            const data = dataset.data;

            const options = config.options || {};
            const yMax = options.scales?.y?.max ?? Math.max(...data, 100);
            const yMin = options.scales?.y?.beginAtZero ? 0 : Math.min(...data);
            const chartYRange = yMax - yMin || 1;

            const padding = 50;
            const topPadding = 40;
            const rightPadding = 20;
            const chartHeight = height - padding - topPadding;
            const chartWidth = width - padding - rightPadding;

            const xStep = chartWidth / (labels.length - 1 || 1);
            const yRatio = chartHeight / chartYRange;

            const desiredLines = 12;
            const stepSize = Math.ceil(chartYRange / desiredLines / 5) * 5;

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = "#fff";
            ctx.fillRect(0, 0, width, height);

            ctx.strokeStyle = "#e6e6e6";
            ctx.lineWidth = 1;
            ctx.font = "10px sans-serif";
            ctx.textAlign = "right";
            ctx.textBaseline = "middle";
            ctx.fillStyle = "#333";

            for (let y = yMin; y <= yMax; y += stepSize) {
                const yPos = height - padding - (y - yMin) * yRatio;
                ctx.beginPath();
                ctx.moveTo(padding, yPos);
                ctx.lineTo(width - rightPadding, yPos);
                ctx.stroke();
                ctx.fillText(y.toString(), padding - 8, yPos);
            }

            ctx.textAlign = "center";
            ctx.textBaseline = "top";

            for (let i = 0; i < labels.length; i++) {
                const x = padding + i * xStep;
                if (i % Math.ceil(labels.length / 20) === 0) {
                    ctx.beginPath();
                    ctx.moveTo(x, topPadding);
                    ctx.lineTo(x, height - padding);
                    ctx.stroke();
                    ctx.save();
                    ctx.translate(x, height - padding + 10);
                    ctx.rotate(-Math.PI / 4);
                    ctx.fillText(labels[i], 0, 0);
                    ctx.restore();
                }
            }

            ctx.beginPath();
            ctx.strokeStyle = dataset.borderColor || "#4bc0c0";
            ctx.lineWidth = dataset.borderWidth || 2;

            data.forEach((val, i) => {
                const x = padding + i * xStep;
                const y = height - padding - (val - yMin) * yRatio;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });

            ctx.stroke();

            ctx.lineTo(padding + (data.length - 1) * xStep, height - padding);
            ctx.lineTo(padding, height - padding);
            ctx.closePath();
            ctx.fillStyle = dataset.backgroundColor || "rgba(75, 192, 192, 0.2)";
            ctx.fill();

            ctx.fillStyle = dataset.borderColor || "#4bc0c0";
            data.forEach((val, i) => {
                const x = padding + i * xStep;
                const y = height - padding - (val - yMin) * yRatio;
                ctx.beginPath();
                ctx.arc(x, y, 2.5, 0, 2 * Math.PI);
                ctx.fill();
            });

            if (hoverIndex !== -1) {


           const x = padding + hoverIndex * xStep;
           const y = height - padding - (data[hoverIndex] - yMin) * yRatio;
           ctx.fillStyle = "#008000";
           ctx.beginPath();
           ctx.arc(x, y, 4, 0, 2 * Math.PI);
           ctx.fill();

           const label = labels[hoverIndex];
           const value = data[hoverIndex].toFixed(2);
           const content = [label, value];

           ctx.font = "11px sans-serif";
           const paddingBox = 6;
           const lineHeight = 14;

           const textWidths = content.map(text => ctx.measureText(text).width);
           const boxWidth = Math.max(...textWidths) + paddingBox * 2;
           const boxHeight = lineHeight * content.length + paddingBox * 2;

           const boxOffset = 8;
           const isOverflowingRight = x + boxOffset + boxWidth > width - 10;
           const boxX = isOverflowingRight ? x - boxOffset - boxWidth : x + boxOffset;

           ctx.fillStyle = "#fff";
           ctx.strokeStyle = "#666";
           ctx.lineWidth = 1;
           ctx.beginPath();
           ctx.rect(boxX, y - boxHeight / 2, boxWidth, boxHeight);
           ctx.stroke();
           ctx.fill();

           ctx.fillStyle = "#000";
           ctx.textAlign = "left";
           ctx.textBaseline = "top";

           content.forEach((text, i) => {
           ctx.fillText(text, boxX + paddingBox, y - boxHeight / 2 + paddingBox + i * lineHeight);
    });
}

            ctx.textAlign = "left";
            ctx.textBaseline = "middle";
            ctx.fillStyle = "#4bc0c0";
            ctx.strokeStyle = "#4bc0c0";
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.rect(width / 2 - 50, 10, 12, 12);
            ctx.stroke();
            ctx.fillRect(width / 2 - 50, 10, 12, 12);
            ctx.font = "12px sans-serif";
            ctx.fillStyle = "#333";
            ctx.fillText(dataset.label || "", width / 2 - 34, 16);
        }

        function handleMouseMove(e) {
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const labels = config.data.labels;
            const width = rect.width;
            const padding = 50;
            const rightPadding = 20;
            const chartWidth = width - padding - rightPadding;
            const xStep = chartWidth / (labels.length - 1 || 1);
            const index = Math.round((x - padding) / xStep);
            if (index >= 0 && index < labels.length) {
                hoverIndex = index;
                render();
            } else {
                hoverIndex = -1;
                render();
            }
        }

        function handleMouseLeave() {
            hoverIndex = -1;
            render();
        }

        canvas.addEventListener("mousemove", handleMouseMove);
        canvas.addEventListener("mouseleave", handleMouseLeave);

        function delayedRender() {
            requestAnimationFrame(() => setTimeout(render, 0));
        }

        if (typeof ResizeObserver !== "undefined") {
            new ResizeObserver(delayedRender).observe(canvas.parentElement || canvas);
        } else {
            window.addEventListener("resize", delayedRender);
        }

        delayedRender();
    }

    window.Chart = function (ctx, config) {
        return new DfehcChart(ctx.canvas, config);
    };
})();
