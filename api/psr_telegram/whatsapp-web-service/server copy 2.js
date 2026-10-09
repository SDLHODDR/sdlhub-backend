const path = require("path");
require("dotenv").config({
    path: path.resolve(__dirname, "../../../.env")
});
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
let retryAttempt = 0;
let retryTimer = null;
let initializationInProgress = false;

/*
|--------------------------------------------------------------------------
| WhatsApp Client
|--------------------------------------------------------------------------
*/

const clientId = process.env.WHATSAPP_CLIENT_ID || "psr-whatsapp";
const authPath = path.resolve(__dirname, ".wwebjs_auth");
const webCacheType = process.env.WHATSAPP_WEB_CACHE_TYPE || "none";

if (!["local", "remote", "none"].includes(webCacheType)) {
    throw new Error("WHATSAPP_WEB_CACHE_TYPE must be local, remote, or none");
}

const createWhatsAppClient = () => new Client({
    authStrategy: new LocalAuth({
        clientId,
        dataPath: authPath
    }),
    webVersionCache: {
        type: webCacheType,
        path: path.resolve(__dirname, ".wwebjs_cache")
    },
    puppeteer: {
        headless: process.env.WHATSAPP_HEADLESS?.toLowerCase() !== "false",
        args: [
            "--no-sandbox",
            "--disable-setuid-sandbox",
            "--disable-dev-shm-usage"
        ]
    }
});

let client = createWhatsAppClient();

/*
|--------------------------------------------------------------------------
| QR Event
|--------------------------------------------------------------------------
*/

const registerClientEvents = (whatsAppClient) => {
    whatsAppClient.on("qr", (qr) => {
        console.log("\n================================");
        console.log("SCAN WHATSAPP QR");
        console.log("================================\n");
        qrcode.generate(qr, {
            small: true
        });
    });

    whatsAppClient.on("authenticated", () => {
        console.log("WhatsApp Authenticated");
    });

    whatsAppClient.on("auth_failure", (msg) => {
        isReady = false;
        console.error("WhatsApp Auth Failure:", msg);
    });

    whatsAppClient.on("ready", () => {
        if (whatsAppClient !== client) return;

        isReady = true;
        retryAttempt = 0;

        console.log("\n================================");
        console.log("WhatsApp Client Ready");
        console.log("================================\n");
    });

    whatsAppClient.on("disconnected", (reason) => {
        if (whatsAppClient !== client) return;

        console.error("WhatsApp Disconnected:", reason);
        isReady = false;
        scheduleClientRestart();
    });

    whatsAppClient.on("error", (error) => {
        console.error("WhatsApp Client Error:", error);
    });
};

const initializeClient = async () => {
    if (initializationInProgress) return;

    initializationInProgress = true;
    try {
        await client.initialize();
    } catch (error) {
        isReady = false;
        console.error("WhatsApp Initialization Failed:", error);

        const failedPage = client.pupPage;
        if (failedPage && !failedPage.isClosed()) {
            let pageLocation = failedPage.url();
            try {
                const parsedUrl = new URL(pageLocation);
                pageLocation = `${parsedUrl.origin}${parsedUrl.pathname}`;
            } catch (inspectionError) {
                console.error("WhatsApp Page URL Inspection Failed:", inspectionError.message);
            }

            let pageTitle = "unavailable";
            try {
                pageTitle = await failedPage.title();
            } catch (inspectionError) {
                console.error("WhatsApp Page Title Inspection Failed:", inspectionError.message);
            }

            console.error("WhatsApp Browser Page:", { location: pageLocation, title: pageTitle });
        }

        scheduleClientRestart();
    } finally {
        initializationInProgress = false;
    }
};

const scheduleClientRestart = () => {
    if (retryTimer) return;

    const delay = Math.min(5000 * (2 ** retryAttempt), 60000);
    retryAttempt += 1;
    console.log(`Retrying WhatsApp initialization in ${delay / 1000} seconds`);

    retryTimer = setTimeout(async () => {
        const previousClient = client;

        try {
            await previousClient.destroy();
        } catch (error) {
            console.error("WhatsApp Client Cleanup Failed:", error.message);
        }

        if (client === previousClient) {
            client = createWhatsAppClient();
            registerClientEvents(client);
        }

        retryTimer = null;
        initializeClient();
    }, delay);
};

registerClientEvents(client);

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

const PORT = Number(process.env.WHATSAPP_PORT || 5002);

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

initializeClient();