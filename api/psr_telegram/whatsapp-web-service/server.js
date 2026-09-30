//require("dotenv").config();

const express = require("express");
const cors = require("cors");
const qrcode = require("qrcode-terminal");
const {
    Client,
    LocalAuth
} = require("whatsapp-web.js");

const app = express();

app.use(cors());
app.use(express.json());

let isReady = false;

/*
|--------------------------------------------------------------------------
| WhatsApp Client
|--------------------------------------------------------------------------
*/

const client = new Client({
    authStrategy: new LocalAuth({ clientId: "psr-whatsapp" }),

    puppeteer: {
        headless: false, // change to true after testing
        args: [
            "--no-sandbox",
            "--disable-setuid-sandbox"
        ]
    }
});

/*
|--------------------------------------------------------------------------
| QR Event
|--------------------------------------------------------------------------
*/

client.on("qr", (qr) => {
    console.log("\n================================");
    console.log("SCAN WHATSAPP QR");
    console.log("================================\n");
    qrcode.generate(qr, {
        small: true
    });
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
    console.log("WhatsApp Auth Failure");
    console.log(msg);
});

/*
|--------------------------------------------------------------------------
| Ready
|--------------------------------------------------------------------------
*/

client.on("ready", () => {

    isReady = true;

    console.log("\n================================");
    console.log("WhatsApp Client Ready");
    console.log("================================\n");
});

/*
|--------------------------------------------------------------------------
| Disconnect
|--------------------------------------------------------------------------
*/

client.on("disconnected", (reason) => {
    console.log("WhatsApp Disconnected:",reason);
    isReady = false;

    setTimeout(() => {
        console.log("Reinitializing WhatsApp...");
        client.initialize();
    }, 5000);
});

/*
|--------------------------------------------------------------------------
| Send Message API
|--------------------------------------------------------------------------
*/

app.post(
    "/send-message",
    async (req, res) => {
        try {
            if (!isReady) {
                return res.status(503).json({
                    success: false,
                    message:
                        "WhatsApp Client Not Ready"
                });
            }

            let {
                mobile,
                message
            } = req.body;

            if (!mobile || !message) {

                return res.status(400).json({
                    success: false,
                    message:
                        "Mobile and message required"
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Number
            |--------------------------------------------------------------------------
            */

            mobile = mobile
                .toString()
                .replace(/\D/g, "");

            if (
                !mobile.startsWith("91")
            ) {
                mobile = "91" + mobile;
            }

            console.log(
                "Sending Message To:",
                mobile
            );

            /*
            |--------------------------------------------------------------------------
            | Check WhatsApp User
            |--------------------------------------------------------------------------
            */

            const numberId =
                await client.getNumberId(
                    mobile
                );

            if (!numberId) {

                return res.status(404).json({
                    success: false,
                    message:
                        "Number not found on WhatsApp",
                    mobile
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Send Message
            |--------------------------------------------------------------------------
            */

            const response =
                await client.sendMessage(
                    numberId._serialized,
                    message
                );

            return res.json({
                success: true,
                message:
                    "Message sent successfully",
                data: response
            });

        } catch (error) {

            console.error(
                "WhatsApp Send Error:",
                error
            );

            return res.status(500).json({
                success: false,
                error: error.message
            });
        }
    }
);

/*
|--------------------------------------------------------------------------
| Health Check API
|--------------------------------------------------------------------------
*/

app.get("/", (req, res) => {

    return res.json({
        success: true,
        ready: isReady,
        message:
            "WhatsApp Service Running"
    });
});

/*
|--------------------------------------------------------------------------
| Start Server
|--------------------------------------------------------------------------
*/

const PORT = process.env.PORT || 5002;

app.listen(PORT, () => {

    console.log(
        `WhatsApp Service Running on ${PORT}`
    );
});

/*
|--------------------------------------------------------------------------
| Initialize Client
|--------------------------------------------------------------------------
*/

client.initialize();