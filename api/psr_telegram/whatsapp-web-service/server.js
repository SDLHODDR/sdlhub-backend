const path = require("path");
const fs = require("fs");

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
| WhatsApp Configuration
|--------------------------------------------------------------------------
*/

const clientId =
    process.env.WHATSAPP_CLIENT_ID || "psr-whatsapp";

const authPath =
    path.resolve(__dirname, ".wwebjs_auth");

const webCacheType =
    process.env.WHATSAPP_WEB_CACHE_TYPE || "none";

if (!["local", "remote", "none"].includes(webCacheType)) {
    throw new Error(
        "WHATSAPP_WEB_CACHE_TYPE must be local, remote, or none"
    );
}

/*
|--------------------------------------------------------------------------
| Create WhatsApp Client
|--------------------------------------------------------------------------
*/

const createWhatsAppClient = () => {

    console.log(
        "Creating WhatsApp Client:",
        clientId
    );

    console.log(
        "WhatsApp Auth Path:",
        authPath
    );

    return new Client({

        authStrategy: new LocalAuth({
            clientId,
            dataPath: authPath
        }),

        webVersionCache: {
            type: webCacheType,
            path: path.resolve(
                __dirname,
                ".wwebjs_cache"
            )
        },

        puppeteer: {

            headless:
                process.env.WHATSAPP_HEADLESS
                    ?.toLowerCase() !== "false",

            args: [
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-dev-shm-usage"
            ]
        }
    });
};

let client = createWhatsAppClient();

/*
|--------------------------------------------------------------------------
| Get WhatsApp Session Directory
|--------------------------------------------------------------------------
*/

const getSessionPath = () => {

    return path.join(
        authPath,
        `session-${clientId}`
    );
};

/*
|--------------------------------------------------------------------------
| Remove Stale Chromium Lock Files
|--------------------------------------------------------------------------
|
| IMPORTANT:
| This does NOT delete the WhatsApp session.
|
| It only removes Chromium lock files:
|
| SingletonLock
| SingletonCookie
| SingletonSocket
|
|--------------------------------------------------------------------------
*/

const cleanupChromiumLockFiles = () => {

    const sessionPath =
        getSessionPath();

    const lockFiles = [
        "SingletonLock",
        "SingletonCookie",
        "SingletonSocket"
    ];

    console.log(
        "Checking Chromium session locks:",
        sessionPath
    );

    for (const fileName of lockFiles) {

        const filePath =
            path.join(
                sessionPath,
                fileName
            );

        try {

            if (fs.existsSync(filePath)) {

                fs.unlinkSync(filePath);

                console.log(
                    `Removed stale Chromium lock: ${fileName}`
                );
            }

        } catch (error) {

            console.error(
                `Unable to remove Chromium lock ${fileName}:`,
                error.message
            );
        }
    }
};

/*
|--------------------------------------------------------------------------
| Safely Destroy WhatsApp Client
|--------------------------------------------------------------------------
*/

const destroyClientSafely = async (
    whatsAppClient
) => {

    if (!whatsAppClient) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Destroy whatsapp-web.js client
    |--------------------------------------------------------------------------
    */

    try {

        await whatsAppClient.destroy();

        console.log(
            "WhatsApp Client Destroyed"
        );

    } catch (error) {

        console.error(
            "WhatsApp Client Destroy Failed:",
            error.message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Close Puppeteer Browser
    |--------------------------------------------------------------------------
    */

    try {

        if (whatsAppClient.pupBrowser) {

            const browser =
                whatsAppClient.pupBrowser;

            if (browser.isConnected()) {

                await browser.close();

                console.log(
                    "Puppeteer Browser Closed"
                );
            }
        }

    } catch (error) {

        console.error(
            "Puppeteer Browser Cleanup Failed:",
            error.message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Close Puppeteer Page
    |--------------------------------------------------------------------------
    */

    try {

        if (
            whatsAppClient.pupPage &&
            !whatsAppClient.pupPage.isClosed()
        ) {

            await whatsAppClient.pupPage.close();

            console.log(
                "Puppeteer Page Closed"
            );
        }

    } catch (error) {

        console.error(
            "Puppeteer Page Cleanup Failed:",
            error.message
        );
    }
};

/*
|--------------------------------------------------------------------------
| QR Event
|--------------------------------------------------------------------------
*/

const registerClientEvents = (
    whatsAppClient
) => {

    whatsAppClient.on(
        "qr",
        (qr) => {

            console.log(
                "\n================================"
            );

            console.log(
                "SCAN WHATSAPP QR"
            );

            console.log(
                "================================\n"
            );

            qrcode.generate(
                qr,
                {
                    small: true
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Authenticated
    |--------------------------------------------------------------------------
    */

    whatsAppClient.on(
        "authenticated",
        () => {

            console.log(
                "WhatsApp Authenticated"
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Authentication Failure
    |--------------------------------------------------------------------------
    */

    whatsAppClient.on(
        "auth_failure",
        (msg) => {

            isReady = false;

            console.error(
                "WhatsApp Auth Failure:",
                msg
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Ready
    |--------------------------------------------------------------------------
    */

    whatsAppClient.on(
        "ready",
        () => {

            /*
            | Only accept ready event from
            | the currently active client.
            */

            if (whatsAppClient !== client) {
                return;
            }

            isReady = true;

            retryAttempt = 0;

            console.log(
                "\n================================"
            );

            console.log(
                "WhatsApp Client Ready"
            );

            console.log(
                "================================\n"
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Disconnected
    |--------------------------------------------------------------------------
    */

    whatsAppClient.on(
        "disconnected",
        (reason) => {

            if (whatsAppClient !== client) {
                return;
            }

            console.error(
                "WhatsApp Disconnected:",
                reason
            );

            isReady = false;

            scheduleClientRestart();
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Client Error
    |--------------------------------------------------------------------------
    */

    whatsAppClient.on(
        "error",
        (error) => {

            console.error(
                "WhatsApp Client Error:",
                error
            );
        }
    );
};

/*
|--------------------------------------------------------------------------
| Initialize WhatsApp Client
|--------------------------------------------------------------------------
*/

const initializeClient = async () => {

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate initialization
    |--------------------------------------------------------------------------
    */

    if (initializationInProgress) {

        console.log(
            "WhatsApp initialization already in progress"
        );

        return;
    }

    initializationInProgress = true;

    try {

        console.log(
            "\n================================"
        );

        console.log(
            "Initializing WhatsApp Client..."
        );

        console.log(
            "================================\n"
        );

        await client.initialize();

    } catch (error) {

        isReady = false;

        console.error(
            "WhatsApp Initialization Failed:",
            error
        );

        /*
        |--------------------------------------------------------------------------
        | Cleanup failed client
        |--------------------------------------------------------------------------
        */

        const failedClient =
            client;

        await destroyClientSafely(
            failedClient
        );

        /*
        |--------------------------------------------------------------------------
        | Remove stale Chromium lock files
        |--------------------------------------------------------------------------
        */

        cleanupChromiumLockFiles();

        /*
        |--------------------------------------------------------------------------
        | Schedule restart
        |--------------------------------------------------------------------------
        */

        scheduleClientRestart();

    } finally {

        initializationInProgress = false;
    }
};

/*
|--------------------------------------------------------------------------
| Schedule Client Restart
|--------------------------------------------------------------------------
*/

const scheduleClientRestart = () => {

    /*
    |--------------------------------------------------------------------------
    | Don't schedule multiple timers
    |--------------------------------------------------------------------------
    */

    if (retryTimer) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Exponential Backoff
    |
    | 5 sec
    | 10 sec
    | 20 sec
    | 40 sec
    | 60 sec
    | 60 sec...
    |--------------------------------------------------------------------------
    */

    const delay =
        Math.min(
            5000 * (2 ** retryAttempt),
            60000
        );

    retryAttempt += 1;

    console.log(
        `Retrying WhatsApp initialization in ${delay / 1000} seconds`
    );

    retryTimer = setTimeout(
        async () => {

            retryTimer = null;

            const previousClient =
                client;

            /*
            |--------------------------------------------------------------------------
            | Destroy previous client
            |--------------------------------------------------------------------------
            */

            await destroyClientSafely(
                previousClient
            );

            /*
            |--------------------------------------------------------------------------
            | Cleanup stale Chromium locks
            |--------------------------------------------------------------------------
            */

            cleanupChromiumLockFiles();

            /*
            |--------------------------------------------------------------------------
            | Create fresh client
            |--------------------------------------------------------------------------
            */

            client =
                createWhatsAppClient();

            /*
            |--------------------------------------------------------------------------
            | Register events for new client
            |--------------------------------------------------------------------------
            */

            registerClientEvents(
                client
            );

            /*
            |--------------------------------------------------------------------------
            | Initialize new client
            |--------------------------------------------------------------------------
            */

            await initializeClient();

        },
        delay
    );
};

/*
|--------------------------------------------------------------------------
| Register Initial Client Events
|--------------------------------------------------------------------------
*/

registerClientEvents(
    client
);

/*
|--------------------------------------------------------------------------
| Send Message API
|--------------------------------------------------------------------------
*/

app.post(
    "/send-message",
    async (req, res) => {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check WhatsApp Ready
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Validate Input
            |--------------------------------------------------------------------------
            */

            if (!mobile || !message) {

                return res.status(400).json({
                    success: false,
                    message:
                        "Mobile and message required"
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Mobile Number
            |--------------------------------------------------------------------------
            */

            mobile = mobile
                .toString()
                .replace(/\D/g, "");

            /*
            |--------------------------------------------------------------------------
            | Add India Country Code
            |--------------------------------------------------------------------------
            */

            if (!mobile.startsWith("91")) {

                mobile =
                    "91" + mobile;
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

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

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

                error:
                    error.message
            });
        }
    }
);

/*
|--------------------------------------------------------------------------
| Health Check API
|--------------------------------------------------------------------------
*/

app.get(
    "/",
    (req, res) => {

        return res.json({

            success: true,

            ready: isReady,

            message:
                "WhatsApp Service Running"
        });
    }
);

/*
|--------------------------------------------------------------------------
| Start Server
|--------------------------------------------------------------------------
*/

const PORT =
    Number(
        process.env.WHATSAPP_PORT || 5002
    );

app.listen(
    PORT,
    () => {

        console.log(
            `WhatsApp Service Running on ${PORT}`
        );
    }
);

/*
|--------------------------------------------------------------------------
| Initialize WhatsApp Client
|--------------------------------------------------------------------------
*/

initializeClient();