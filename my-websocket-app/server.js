// server.js - WebSocket Server (FIXED VERSION)
// ✅ FIXED: /socket.io/ → /api/trigger (avoids Socket.io protocol conflict)
// ✅ FIXED: Emits both 'dashboard_update' AND specific event names
// ✅ ADDED: 'join' event handler for channel subscription
// ✅ ADDED: Better error handling + request logging

const express = require('express');
const app = express();
const http = require('http').createServer(app);
const io = require('socket.io')(http, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    },
    pingTimeout: 60000,
    pingInterval: 25000
});

const connectedClients = new Map();

// Increase JSON payload limit (para sa malalaking payload)
app.use(express.json({ limit: '1mb' }));

// ==========================================
// HTTP endpoint for PHP to trigger events
// ✅ CHANGED: /socket.io/ → /api/trigger
// ==========================================
app.post('/api/trigger', (req, res) => {
    try {
        const data = req.body || {};
        const eventType = data.event_type || 'unknown';
        const payload   = data.payload || {};
        const timestamp = data.timestamp || new Date().toISOString();
        const source    = data.source || 'system';

        console.log(`📨 [HTTP] Trigger received: ${eventType} from ${source}`);

        // ✅ 1. Emit generic dashboard update (para sa dashboard widgets)
        io.emit('dashboard_update', {
            event_type: eventType,
            payload:    payload,
            timestamp:  timestamp,
            source:     source
        });

        // ✅ 2. Emit the SPECIFIC event name (para sa reminder_sent, trainee_returned, etc.)
        // This is what evaluation.php is listening for via socketInstance.on(eventType, ...)
        if (eventType !== 'dashboard_update') {
            io.emit(eventType, payload);
            console.log(`📡 [Socket.io] Emitted specific event: ${eventType}`);
        }

        res.json({
            success: true,
            message: 'Event broadcasted',
            event:   eventType,
            clients: connectedClients.size
        });

    } catch (err) {
        console.error('❌ [HTTP] Trigger error:', err);
        res.status(500).json({
            success: false,
            error: err.message
        });
    }
});

// ==========================================
// Socket.io connection handling
// ==========================================
io.on('connection', (socket) => {
    console.log('🔌 User connected:', socket.id);

    // --------------------------------------------------
    // Handle 'authenticate' event
    // --------------------------------------------------
    socket.on('authenticate', (data) => {
        data = data || {};
        socket.userId         = data.userId;
        socket.userRole       = data.userRole;
        socket.userDepartment = data.userDepartment;

        connectedClients.set(socket.id, {
            userId:       data.userId,
            role:         data.userRole,
            department:   data.userDepartment,
            connectedAt:  new Date().toISOString()
        });

        console.log(`✅ User ${data.userId} (${data.userRole}) authenticated`);
        console.log(`📊 Total connected clients: ${connectedClients.size}`);

        socket.emit('authenticated', {
            status:  'success',
            message: 'Connected to real-time dashboard'
        });
    });

    // --------------------------------------------------
    // Handle 'join' event — para sa channel subscription
    // (evaluation.php line 4124: socketInstance.emit('join', { channel: 'compliance-channel' }))
    // --------------------------------------------------
    socket.on('join', (data) => {
        const channel = (data && data.channel) ? data.channel : 'compliance-channel';
        socket.join(channel);
        console.log(`✅ Socket ${socket.id} joined channel: ${channel}`);

        socket.emit('joined', {
            status:  'success',
            channel: channel,
            message: `Subscribed to ${channel}`
        });
    });

    // --------------------------------------------------
    // Handle 'dashboard_sync' event (legacy support)
    // --------------------------------------------------
    socket.on('dashboard_sync', (data) => {
        data = data || {};
        console.log(`📊 Dashboard sync: ${data.event_type}`);
        socket.broadcast.emit('dashboard_update', {
            event_type: data.event_type,
            payload:    data.payload || {},
            timestamp:  new Date().toISOString()
        });
    });

    // --------------------------------------------------
    // Ping/Pong for keep-alive
    // --------------------------------------------------
    socket.on('ping', () => {
        socket.emit('pong', { timestamp: new Date().toISOString() });
    });

    // --------------------------------------------------
    // Handle disconnect
    // --------------------------------------------------
    socket.on('disconnect', (reason) => {
        console.log(`❌ User disconnected: ${socket.id} (${reason})`);
        connectedClients.delete(socket.id);
    });

    // --------------------------------------------------
    // Handle errors
    // --------------------------------------------------
    socket.on('error', (err) => {
        console.error(`❌ Socket error (${socket.id}):`, err);
    });
});

// ==========================================
// Health check endpoints
// ==========================================
app.get('/health', (req, res) => {
    res.json({
        status:  'online',
        clients: connectedClients.size,
        uptime:  process.uptime(),
        time:    new Date().toISOString()
    });
});

app.get('/api/health', (req, res) => {
    res.json({
        status:  'online',
        clients: connectedClients.size,
        uptime:  process.uptime(),
        time:    new Date().toISOString()
    });
});

// ==========================================
// Start server
// ==========================================
const PORT = process.env.PORT || 3000;
http.listen(PORT, () => {
    console.log('==========================================');
    console.log(`🚀 WebSocket Server running at http://localhost:${PORT}`);
    console.log(`📡 HTTP trigger endpoint: POST http://localhost:${PORT}/api/trigger`);
    console.log(`❤️  Health check:         GET  http://localhost:${PORT}/api/health`);
    console.log('==========================================');
});