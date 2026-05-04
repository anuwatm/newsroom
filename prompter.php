<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$storyId = intval($_GET['id'] ?? 0);
if (!$storyId) {
    die("Invalid Story ID");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Teleprompter - Newsroom</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #000;
            color: #fff;
            font-family: 'Sarabun', sans-serif;
            overflow: hidden; /* Hide scrollbars */
        }
        #prompter-container {
            width: 80%;
            margin: 0 auto;
            height: 100vh;
            position: relative;
            box-sizing: border-box;
            padding: 50vh 0; /* Start in middle of screen */
        }
        #text-content {
            font-size: 60px;
            line-height: 1.5;
            font-weight: 600;
            white-space: pre-wrap;
            text-align: center;
            transform-origin: center top;
            transition: transform 0.3s ease;
        }
        .marker {
            position: fixed;
            top: 50%;
            left: 5%;
            right: 5%;
            height: 2px;
            background: rgba(255, 0, 0, 0.5);
            z-index: 10;
            pointer-events: none;
        }
        .marker::before {
            content: "▶";
            position: absolute;
            left: -30px;
            top: -15px;
            color: red;
            font-size: 30px;
        }
        .marker::after {
            content: "◀";
            position: absolute;
            right: -30px;
            top: -15px;
            color: red;
            font-size: 30px;
        }
        #controls {
            position: fixed;
            bottom: -100px;
            left: 0;
            width: 100%;
            background: rgba(30, 30, 30, 0.9);
            padding: 20px;
            box-sizing: border-box;
            display: flex;
            justify-content: center;
            gap: 20px;
            align-items: center;
            transition: bottom 0.3s ease;
            z-index: 100;
        }
        body:hover #controls {
            bottom: 0;
        }
        .ctrl-group {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ccc;
        }
        button {
            background: #333;
            color: #fff;
            border: 1px solid #555;
            padding: 10px 20px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        button:hover {
            background: #555;
        }
        input[type="range"] {
            width: 150px;
        }
        .active-btn {
            background: #0d6efd;
            border-color: #0d6efd;
        }
    </style>
</head>
<body>
    <div class="marker"></div>
    <div id="prompter-container">
        <div id="text-content">Loading script...</div>
    </div>

    <div id="controls">
        <button id="btn-play" onclick="togglePlay()"><i class="fa-solid fa-play"></i> Play (Space)</button>
        <button id="btn-mirror" onclick="toggleMirror()"><i class="fa-solid fa-arrows-left-right"></i> Mirror</button>
        <div class="ctrl-group">
            <i class="fa-solid fa-text-height"></i> Font Size:
            <input type="range" id="fontSize" min="40" max="150" value="60" oninput="updateFont()">
        </div>
        <div class="ctrl-group">
            <i class="fa-solid fa-gauge-high"></i> Speed:
            <input type="range" id="speed" min="1" max="100" value="20">
        </div>
        <button onclick="window.close()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>

    <script>
        const storyId = <?php echo $storyId; ?>;
        const textContent = document.getElementById('text-content');
        let isPlaying = false;
        let isMirrored = false;
        let scrollPos = 0;
        let reqAnimFrame;

        // Fetch story data
        fetch(`api.php?action=get_story&id=${storyId}`)
            .then(res => res.json())
            .then(res => {
                if(res.success && res.data) {
                    // Extract anchor text from JSON
                    let anchorText = '';
                    try {
                        const parsed = JSON.parse(res.data.content);
                        anchorText = parsed.anchor || '';
                    } catch(e) {
                        anchorText = res.data.content;
                    }
                    
                    // Basic cleanup: remove span tags that might have dark colors, force white text
                    let cleanHtml = anchorText.replace(/color:\s*[^;"]+/g, 'color: #fff');
                    textContent.innerHTML = cleanHtml;
                } else {
                    textContent.innerHTML = "Failed to load script.";
                }
            });

        function togglePlay() {
            isPlaying = !isPlaying;
            const btn = document.getElementById('btn-play');
            if(isPlaying) {
                btn.innerHTML = '<i class="fa-solid fa-pause"></i> Pause (Space)';
                btn.classList.add('active-btn');
                scrollLoop();
            } else {
                btn.innerHTML = '<i class="fa-solid fa-play"></i> Play (Space)';
                btn.classList.remove('active-btn');
                cancelAnimationFrame(reqAnimFrame);
            }
        }

        function toggleMirror() {
            isMirrored = !isMirrored;
            const btn = document.getElementById('btn-mirror');
            if(isMirrored) {
                textContent.style.transform = 'scaleX(-1)';
                btn.classList.add('active-btn');
            } else {
                textContent.style.transform = 'scaleX(1)';
                btn.classList.remove('active-btn');
            }
        }

        function updateFont() {
            const size = document.getElementById('fontSize').value;
            textContent.style.fontSize = size + 'px';
        }

        function scrollLoop() {
            if(!isPlaying) return;
            const speed = document.getElementById('speed').value;
            // Map 1-100 to actual pixel scroll speed
            const pxPerFrame = (speed / 100) * 3;
            window.scrollBy(0, pxPerFrame);
            reqAnimFrame = requestAnimationFrame(scrollLoop);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if(e.code === 'Space') {
                e.preventDefault();
                togglePlay();
            } else if(e.code === 'ArrowUp') {
                e.preventDefault();
                let speedCtrl = document.getElementById('speed');
                speedCtrl.value = Math.min(100, parseInt(speedCtrl.value) + 5);
            } else if(e.code === 'ArrowDown') {
                e.preventDefault();
                let speedCtrl = document.getElementById('speed');
                speedCtrl.value = Math.max(1, parseInt(speedCtrl.value) - 5);
            }
        });
    </script>
</body>
</html>
