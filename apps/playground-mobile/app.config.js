require("dotenv/config");

function readOptional(value) {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : undefined;
}

module.exports = ({ config }) => ({
  ...config,
  plugins: [...(Array.isArray(config.plugins) ? config.plugins : []), "expo-audio"],
  extra: {
    ...(config.extra ?? {}),
    NAVAI_API_URL: readOptional(process.env.NAVAI_API_URL),
    NAVAI_FUNCTIONS_FOLDERS: readOptional(process.env.NAVAI_FUNCTIONS_FOLDERS),
    NAVAI_ROUTES_FILE: readOptional(process.env.NAVAI_ROUTES_FILE),
    NAVAI_REALTIME_MODEL: readOptional(process.env.NAVAI_REALTIME_MODEL)
  }
});
