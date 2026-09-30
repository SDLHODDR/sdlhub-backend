
const express = require("express");
const cors = require("cors");
const qrcode = require("qrcode-terminal");
const { Client, LocalAuth } = require("whatsapp-web.js");

const app = express();

app.use(cors());
app.use(express.json());

/*
|--------------------------------------------------------------------------
| WhatsApp Client
|--------------------------------------------------------------------------
*/

const client = new Client({
    authStrategy: new LocalAuth({
        clientId: "psr-whatsapp"
    }),

    puppeteer: {
        headless: true,
        args: ["--no-sandbox"]
    }
});

/*
|--------------------------------------------------------------------------
| QR Code
|--------------------------------------------------------------------------
*/

client.on("qr", (qr) => {
    console.log("\nScan QR Code Below:\n");

    qrcode.generate(qr, {
        small: true
    });
});

/*
|--------------------------------------------------------------------------
| Ready
|--------------------------------------------------------------------------
*/

client.on("ready", () => {
    console.log("WhatsApp Client Ready");
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

client.on("authenticated", () => {
    console.log("WhatsApp Authenticated");
});

client.on("auth_failure", (msg) => {
    console.log("Auth Failure:", msg);
});

/*
|--------------------------------------------------------------------------
| Disconnect
|--------------------------------------------------------------------------
*/

client.on("disconnected", (reason) => {
    console.log("WhatsApp Disconnected:", reason);
});

/*
|--------------------------------------------------------------------------
| Send WhatsApp Message API
|--------------------------------------------------------------------------
*/

app.post("/send-message", async (req, res) => {

    try {

        const {
            mobile,
            message
        } = req.body;

        if (!mobile || !message) {
            return res.status(400).json({
                success: false,
                message: "Mobile and message required"
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Format Number
        |--------------------------------------------------------------------------
        */

        const chatId = "91" + mobile.replace(/\+/g, "") + "@c.us";

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        const response = await client.sendMessage(
            chatId,
            message
        );

        return res.json({
            success: true,
            data: response
        });

    } catch (error) {

        console.log(error);

        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

/*
|--------------------------------------------------------------------------
| Health API
|--------------------------------------------------------------------------
*/

app.get("/", (req, res) => {
    res.send("WhatsApp Service Running");
});

/*
|--------------------------------------------------------------------------
| Start Server
|--------------------------------------------------------------------------
*/

const PORT = 5002;

app.listen(PORT, () => {
    console.log(`WhatsApp Service Running on ${PORT}`);
});

/*
|--------------------------------------------------------------------------
| Initialize WhatsApp
|--------------------------------------------------------------------------
*/

client.initialize();